<?php

declare(strict_types=1);

namespace CatzPrint\Internal\Local;

use CatzPrint\Client;
use CatzPrint\Exception\AuthenticationException;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ConnectionException;
use CatzPrint\Exception\NotFoundException;
use CatzPrint\Exception\OutcomeUnknownException;
use CatzPrint\Exception\ServerException;
use CatzPrint\Exception\StationAttempt;
use CatzPrint\Exception\StationUnreachableException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Internal\ErrorFactory;
use CatzPrint\Internal\HttpClient;
use CatzPrint\Internal\RetryPolicy;
use CatzPrint\Local\AtomicTokenStore;
use CatzPrint\Local\StoredToken;
use CatzPrint\Model\Job;
use CatzPrint\Model\LocalEndpoint;
use CatzPrint\Model\LocalInfo;
use CatzPrint\Model\LocalJob;
use CatzPrint\Model\LocalPairing;
use CatzPrint\Model\LocalStation;
use CatzPrint\Model\LocalStatus;

/**
 * Local printing state for one client: the token, the cached endpoint list,
 * station candidates and the requests to stations.
 *
 * PHP usually runs per web request, so nothing happens in the background:
 * the token (when < 7 days left) and the endpoint list (when older than an
 * hour) are refreshed lazily, after a station answered, and only when the
 * cloud answers; a failed refresh is not retried for 5 minutes (remembered
 * in the token store).
 *
 * @internal
 */
final class LocalState
{
    public const TOKEN_REFRESH_BEFORE = 7 * 86400;
    public const ENDPOINTS_MAX_AGE = 3600;
    public const MDNS_CACHE_TTL = 60;
    public const REFRESH_RETRY_AFTER = 300;
    public const REMINT_INTERVAL = 600;

    public readonly string $storeKey;

    private bool $loaded = false;
    private ?StoredToken $state = null;
    private ?\Throwable $storeError = null;
    private ?\Throwable $refreshError = null;
    /** @var list<MdnsStation>|null */
    private ?array $mdns = null;
    private float $mdnsAt = 0.0;
    /** @var array<string, string> local job id => station URL */
    private array $jobUrls = [];
    /** @var callable(): float */
    private $clock;
    private string $mdnsAddress = Mdns::GROUP;
    private readonly StationTransport $transport;
    private readonly HttpClient $cloudHttp;

    public function __construct(
        private readonly Client $client,
        public readonly LocalConfig $cfg,
        #[\SensitiveParameter] string $apiKey,
    ) {
        $this->storeKey = 'v1|' . substr(hash('sha256', $apiKey), 0, 16) . '|' . $cfg->locationId . '|' . $cfg->deviceName;
        $this->clock = static fn (): float => microtime(true);
        $this->transport = new StationTransport($cfg->connectTimeout, $cfg->requestTimeout, $cfg->caFile);
        $this->cloudHttp = $client->http()->with($cfg->cloudTimeout, new RetryPolicy(0));
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['mode' => $this->cfg->mode, 'storeKey' => $this->storeKey, 'loaded' => $this->loaded];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Local printing state holds tokens and cannot be serialized.');
    }

    /** Test hook: the clock (unix seconds). */
    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
    }

    /** Test hook: where mDNS queries go ("ip:port"). */
    public function setMdnsAddress(string $addr): void
    {
        $this->mdnsAddress = $addr;
        $this->mdns = null;
    }

    /** The single, short (no retries, cloudTimeout) cloud client used by cloud_first, minting and refreshes. */
    public function cloudHttp(): HttpClient
    {
        return $this->cloudHttp;
    }

    public function storeError(): ?\Throwable
    {
        return $this->storeError;
    }

    public function refreshError(): ?\Throwable
    {
        return $this->refreshError;
    }

    private function now(): float
    {
        return (float) ($this->clock)();
    }

    private static function dt(float $t): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $t));

        return $d === false ? new \DateTimeImmutable('@' . (int) $t) : $d;
    }

    private static function ts(?\DateTimeImmutable $d): float
    {
        return $d === null ? 0.0 : (float) $d->format('U.u');
    }

    // ---- token and endpoint state ----------------------------------------

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        try {
            $st = $this->cfg->tokenStore->load($this->storeKey);
            if ($st !== null && $st->token !== '' && preg_match('/^czl1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $st->token) !== 1) {
                // E.g. a redacted placeholder written by a debug dump: no token.
                $this->storeError = new \RuntimeException(StoredToken::isRedactionPlaceholder($st->token)
                    ? 'The token store holds a redacted token placeholder instead of a token; minting a new one.'
                    : 'The token store holds something that is not a local token; minting a new one.');
                $st = clone $st;
                $st->token = '';
                $st->tokenId = '';
                $st->expiresAt = null;
            }
            $this->state = $st === null ? null : clone $st;
        } catch (\Throwable $e) {
            $this->storeError = $e;
        }
    }

    private function snapshot(): ?StoredToken
    {
        $this->ensureLoaded();

        return $this->state === null ? null : clone $this->state;
    }

    private function save(StoredToken $st): void
    {
        $this->state = clone $st;
        try {
            $this->cfg->tokenStore->save($this->storeKey, $st);
        } catch (\Throwable $e) {
            $this->storeError = $e; // never stops printing
        }
    }

    /**
     * Read-modify-write of the stored state, atomically when the store
     * supports it (so rate limits hold across PHP processes).
     *
     * @param callable(StoredToken): bool $fn changes the token and returns true to save it
     */
    private function update(callable $fn): void
    {
        $this->ensureLoaded();
        $store = $this->cfg->tokenStore;
        if ($store instanceof AtomicTokenStore) {
            try {
                $res = $store->update($this->storeKey, static function (?StoredToken $cur) use ($fn): ?StoredToken {
                    $st = $cur === null ? new StoredToken() : clone $cur;

                    return $fn($st) ? $st : null;
                });
                if ($res !== null) {
                    $this->state = clone $res;
                }

                return;
            } catch (\Throwable $e) {
                $this->storeError = $e;
            }
        }
        $st = $this->state === null ? new StoredToken() : clone $this->state;
        if ($fn($st)) {
            $this->save($st);
        }
    }

    /**
     * Mints a fresh local token (bound to locationId unless allLocations)
     * and stores it with the endpoint list that comes with it.
     */
    private function mint(bool $force): StoredToken
    {
        if (!$force && ($st = $this->snapshot()) !== null && $st->usable($this->now())) {
            return $st;
        }
        $params = ['deviceName' => $this->cfg->deviceName];
        if ($this->cfg->tokenTtlDays > 0) {
            $params['ttlDays'] = $this->cfg->tokenTtlDays;
        }
        if ($this->cfg->locationId !== '') {
            $params['locationIds'] = [$this->cfg->locationId];
        }
        $lt = $this->client->localTokens->createWith($this->cloudHttp, $params);
        $st = $this->snapshot() ?? new StoredToken();
        $st->token = $lt->token;
        $st->tokenId = $lt->tokenId;
        $st->expiresAt = $lt->expiresAt;
        if ($lt->stations !== null) {
            $st->stations = $lt->stations;
            $st->stationsUpdatedAt = self::dt($this->now());
        }
        $st->refreshRetryAt = null;
        $this->save($st);

        return $st;
    }

    /** The token for station requests, minting one when none is usable. */
    public function token(): string
    {
        if ($this->cfg->token !== '') {
            return $this->cfg->token;
        }
        $st = $this->snapshot();
        if ($st !== null && $st->usable($this->now())) {
            return $st->token;
        }

        return $this->mint(false)->token;
    }

    /** @return array{0: bool, 1: bool} token stale (< 7 days left), endpoint list stale (> 1 hour) */
    private function needsRefresh(): array
    {
        $st = $this->snapshot();
        $now = $this->now();
        $tok = false;
        if ($this->cfg->token === '') {
            $tok = $st === null || $st->token === '' || self::ts($st->expiresAt) - $now < $this->refreshBefore($st->token);
        }
        $eps = $st === null || !$st->endpointsKnown() || $now - self::ts($st->stationsUpdatedAt) > self::ENDPOINTS_MAX_AGE;

        return [$tok, $eps];
    }

    /**
     * How long before expiry a token is renewed: min(7 days, TTL/2)
     * (addendum 2, SDK-6). The TTL comes from the token's iat/exp claims when
     * they look real, else from tokenTtlDays (default 30).
     */
    private function refreshBefore(#[\SensitiveParameter] string $token): float
    {
        $c = self::tokenClaims($token);
        $iat = $c['iat'] ?? null;
        $exp = $c['exp'] ?? null;
        $ttl = is_int($iat) && is_int($exp) && $exp - $iat >= 3600
            ? (float) ($exp - $iat)
            : (float) (($this->cfg->tokenTtlDays > 0 ? $this->cfg->tokenTtlDays : 30) * 86400);

        return min((float) self::TOKEN_REFRESH_BEFORE, $ttl / 2);
    }

    /**
     * Renews the token when it has less than min(7 days, TTL/2) left and the endpoint
     * list when it is older than an hour (or always, with $force).
     */
    public function refresh(bool $force): void
    {
        if (!$this->client->http()->hasApiKey()) {
            return; // a paired device without an API key has no cloud
        }
        [$tok, $eps] = $this->needsRefresh();
        if ($tok) {
            $before = $this->snapshot()?->stationsUpdatedAt;
            $st = $this->mint(true);
            if ($st->stationsUpdatedAt != $before) {
                return; // the mint brought a fresh endpoint list
            }
        }
        if (!$eps && !$force) {
            return;
        }
        $list = $this->client->localEndpoints->listWith($this->cloudHttp, $this->cfg->locationId);
        $st = $this->snapshot() ?? new StoredToken();
        $st->stations = $list;
        $st->stationsUpdatedAt = self::dt($this->now());
        $st->refreshRetryAt = null;
        $this->save($st);
    }

    /**
     * The lazy refresh (instead of Go's background one): only with an API
     * key and autoRefresh, only when something is stale, and not again for 5
     * minutes after a failure (persisted). Errors are kept, never thrown.
     */
    private function maybeRefresh(): void
    {
        if (!$this->cfg->autoRefresh) {
            return;
        }
        [$tok, $eps] = $this->needsRefresh();
        $this->tryRefresh($tok || $eps);
    }

    /**
     * With discover, the endpoint list is needed before the first station
     * is tried: fetch it when it was never fetched (rate-limited like
     * maybeRefresh()).
     */
    private function ensureEndpoints(): void
    {
        if ($this->cfg->discover && !($this->snapshot()?->endpointsKnown() ?? false)) {
            $this->tryRefresh(true);
        }
    }

    private function tryRefresh(bool $needed): void
    {
        if (!$needed || !$this->client->http()->hasApiKey()) {
            return;
        }
        $now = $this->now();
        if ($now < self::ts($this->snapshot()?->refreshRetryAt)) {
            return;
        }
        try {
            $this->refresh(false);
            $this->refreshError = null;
        } catch (\Throwable $e) {
            $this->refreshError = $e;
            $retry = self::dt($now + self::REFRESH_RETRY_AFTER);
            $this->update(static function (StoredToken $st) use ($retry): bool {
                $st->refreshRetryAt = $retry;

                return true;
            });
        }
    }

    /**
     * The organization this device belongs to, if known without a network
     * call (the client's org ID, or the token's claims).
     */
    private function orgHint(): string
    {
        $org = $this->client->knownOrgId();
        if ($org !== null && $org !== '') {
            return $org;
        }
        $tok = $this->cfg->token !== '' ? $this->cfg->token : ($this->snapshot()?->token ?? '');

        return (string) (self::tokenClaims($tok)['org'] ?? '');
    }

    /**
     * Reads (without verifying) a czl1 token's payload.
     *
     * @return array<string, mixed>
     */
    public static function tokenClaims(#[\SensitiveParameter] string $tok): array
    {
        $parts = explode('.', $tok);
        if (count($parts) !== 3 || $parts[0] !== 'czl1') {
            return [];
        }
        $b = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $c = $b === false ? null : json_decode($b, true);

        return is_array($c) ? $c : [];
    }

    /** The live flag a token implies: paired devices (czd_) are live; local tokens carry it. */
    public static function tokenLive(#[\SensitiveParameter] string $tok): bool
    {
        if (str_starts_with($tok, 'czd_')) {
            return true;
        }

        return (self::tokenClaims($tok)['live'] ?? false) === true;
    }

    // ---- candidates --------------------------------------------------------

    /**
     * One cloud endpoint as targets. Hostnames come only from here (the
     * authenticated cloud list): verified with the CA roots and pinned to the
     * public key the cloud reports, and dialled at the IP in the name only
     * under the station's own label. IP URLs must be private and are pinned
     * to the station's certificate.
     *
     * @return list<StationTarget>
     */
    public static function endpointTargets(LocalEndpoint $ep, string $source): array
    {
        $fp = Net::normalizeFingerprint($ep->certFingerprintSha256);
        $spki = Net::normalizeFingerprint($ep->publicSpkiSha256);
        $out = [];
        foreach ($ep->urls as $raw) {
            try {
                $u = Net::normalizeStationUrl($raw);
            } catch (\InvalidArgumentException) {
                continue;
            }
            [$host] = Net::urlHost($u);
            if (Net::isIp($host)) {
                if (!Net::privateIp($host)) {
                    continue;
                }
                $out[] = new StationTarget($u, $fp, $ep->tlsMode, $ep->stationId, $source);
                continue;
            }
            $label = strtolower($ep->localLabel);
            $parts = explode('.', $host);
            if ($label === '' && count($parts) >= 3) {
                $label = $parts[1]; // the hostname itself came from the cloud
            }
            $out[] = new StationTarget($u, '', $ep->tlsMode, $ep->stationId, $source, $spki, $label);
        }

        return $out;
    }

    private function userPinned(string $fp): bool
    {
        return $fp !== '' && in_array($fp, $this->cfg->fingerprints, true);
    }

    /**
     * How to trust a configured URL: a pin from the "fingerprints" option,
     * else the cloud endpoint list (by URL, or by address and port for IPs),
     * else an mDNS answer for a station on that list.
     *
     * @param list<MdnsStation> $mdns
     */
    private function targetFor(string $u, string $source, ?StoredToken $st, array $mdns): StationTarget
    {
        [$host, $port] = Net::urlHost($u);
        foreach ([$host . ':' . $port, $host] as $k) {
            if (isset($this->cfg->fingerprints[$k])) {
                return new StationTarget($u, $this->cfg->fingerprints[$k], '', '', $source);
            }
        }
        if ($st !== null) {
            foreach ($st->stations as $ep) {
                foreach (self::endpointTargets($ep, $source) as $et) {
                    if ($et->url === $u) {
                        return $et;
                    }
                }
                if (Net::isIp($host) && Net::privateIp($host) && in_array($host, $ep->addresses, true) && (string) $ep->port === $port) {
                    return new StationTarget($u, Net::normalizeFingerprint($ep->certFingerprintSha256), $ep->tlsMode, $ep->stationId, $source);
                }
            }
        }
        foreach ($this->acceptedMdns($mdns, $st) as $d) {
            if (in_array($host, $d->addresses, true) && (string) $d->port === $port) {
                return new StationTarget($u, $d->fingerprint, $d->tlsMode, $d->stationId, $source);
            }
        }

        return new StationTarget($u, '', '', '', $source);
    }

    /**
     * The configured URLs, then (with discover) the cloud endpoint list's
     * URLs, public hostnames before raw IPs.
     *
     * @return list<StationTarget>
     */
    private function primaryTargets(): array
    {
        $st = $this->snapshot();
        $mdns = $this->mdns ?? [];
        $out = [];
        $seen = [];
        foreach ($this->cfg->stationUrls as $u) {
            $seen[$u] = true;
            $out[] = $this->targetFor($u, 'config', $st, $mdns);
        }
        if ($this->cfg->discover && $st !== null) {
            foreach ($st->stations as $ep) {
                if (!$ep->localEnabled || ($this->cfg->locationId !== '' && $ep->locationId !== '' && $ep->locationId !== $this->cfg->locationId)) {
                    continue;
                }
                foreach (self::endpointTargets($ep, 'cloud') as $t) {
                    if (!isset($seen[$t->url])) {
                        $seen[$t->url] = true;
                        $out[] = $t;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Filters mDNS announcements. mDNS is unauthenticated, so it only maps
     * a station ID to its current addresses: an announcement is used for a
     * station on the cloud endpoint list (pinned with the list's fingerprint,
     * port from the list) or one whose announced fingerprint the user
     * configured. Only private addresses are kept; the announced hostname is
     * never used.
     *
     * @param list<MdnsStation> $found
     *
     * @return list<MdnsStation>
     */
    public function acceptedMdns(array $found, ?StoredToken $st): array
    {
        $org = $found === [] ? '' : $this->orgHint();
        $out = [];
        foreach ($found as $d) {
            if ($org !== '' && $d->orgId !== '' && $d->orgId !== $org) {
                continue;
            }
            if ($this->cfg->locationId !== '' && $d->locationId !== '' && $d->locationId !== $this->cfg->locationId) {
                continue;
            }
            $addrs = array_values(array_filter($d->addresses, [Net::class, 'privateIp']));
            $ep = null;
            foreach ($st?->stations ?? [] as $e) {
                if ($e->stationId === $d->stationId) {
                    $ep = $e;
                }
            }
            if ($ep !== null) {
                if (!$ep->localEnabled) {
                    continue;
                }
                $d = $d->with($addrs, Net::normalizeFingerprint($ep->certFingerprintSha256), 'self_signed', $ep->port > 0 ? $ep->port : $d->port);
            } elseif ($this->userPinned($d->fingerprint)) {
                $d = $d->with($addrs, $d->fingerprint, $d->tlsMode, $d->port);
            } else {
                continue;
            }
            if ($d->fingerprint === '' || $d->addresses === []) {
                continue;
            }
            $out[] = $d;
        }

        return $out;
    }

    /** @return list<StationTarget> the pinned IP URLs of an accepted announcement */
    public static function mdnsTargets(MdnsStation $d): array
    {
        $out = [];
        foreach ($d->addresses as $a) {
            $h = str_contains($a, ':') ? '[' . $a . ']' : $a;
            $out[] = new StationTarget('https://' . $h . ':' . $d->port, $d->fingerprint, 'self_signed', $d->stationId, 'mdns');
        }

        return $out;
    }

    /**
     * Runs (or reuses, for a minute) an mDNS browse. Skipped without
     * ext-sockets, and when nothing could be accepted anyway (no cloud
     * endpoint list and no pinned fingerprints).
     *
     * @return list<MdnsStation>
     */
    private function browse(bool $fresh): array
    {
        if (!Mdns::available()) {
            return [];
        }
        $st = $this->snapshot();
        if (($st === null || $st->stations === []) && $this->cfg->fingerprints === []) {
            return [];
        }
        $now = $this->now();
        if (!$fresh && $this->mdns !== null && $now - $this->mdnsAt < self::MDNS_CACHE_TTL) {
            return $this->mdns;
        }
        $this->mdns = Mdns::browse($this->cfg->discoverTimeout, $this->mdnsAddress);
        $this->mdnsAt = $now;

        return $this->mdns;
    }

    /**
     * Calls $try for every candidate in order: configured URLs, cloud
     * endpoint URLs, then (with discover, only if those all failed) stations
     * found over mDNS. $preferred, when set, goes first. Stops when $try
     * returns true.
     *
     * @param callable(StationTarget): bool $try
     */
    private function eachTarget(string $preferred, callable $try): void
    {
        $tried = [];
        $targets = $this->primaryTargets();
        if ($preferred !== '') {
            foreach ($targets as $i => $t) {
                if ($t->url === $preferred) {
                    unset($targets[$i]);
                    array_unshift($targets, $t);
                    break;
                }
            }
        }
        foreach ($targets as $t) {
            $tried[$t->url] = true;
            if ($try($t)) {
                return;
            }
        }
        if (!$this->cfg->discover) {
            return;
        }
        foreach ($this->acceptedMdns($this->browse(false), $this->snapshot()) as $d) {
            foreach (self::mdnsTargets($d) as $t) {
                if (isset($tried[$t->url])) {
                    continue;
                }
                $tried[$t->url] = true;
                if ($try($t)) {
                    return;
                }
            }
        }
    }

    // ---- requests ----------------------------------------------------------

    public const OK = 'ok';
    /** Nothing reached the station, or it refused before acting: 502/504, 503 local_disabled (or no code). */
    public const NOT_DELIVERED = 'not_delivered';
    /** The request was fully written, then no usable answer (timeout, reset, read error, too large, 3xx). */
    public const NO_ANSWER = 'no_answer';
    /** The station answered with an error that is returned to the caller. */
    public const ANSWER = 'answer';

    /**
     * One request to a station, for reads: [unreachable, data, error], where
     * unreachable is "not delivered" or "no answer" (a read can simply be
     * tried on the next station).
     *
     * @param array<string, string>     $query
     * @param array<string, mixed>|null $body
     *
     * @return array{0: bool, 1: array<string, mixed>|null, 2: \Throwable|null}
     */
    private function send(StationTarget $t, string $method, string $path, array $query, #[\SensitiveParameter] string $token, ?array $body, float $extra = 0.0): array
    {
        [$kind, $data, $err] = $this->exchange($t, $method, $path, $query, $token, $body, $extra);

        return [$kind === self::NOT_DELIVERED || $kind === self::NO_ANSWER, $data, $err];
    }

    /**
     * One request to a station, classified (addendum 2, SDK-1): OK,
     * NOT_DELIVERED, NO_ANSWER or ANSWER (an error the station answered).
     *
     * @param array<string, string>     $query
     * @param array<string, mixed>|null $body
     *
     * @return array{0: string, 1: array<string, mixed>|null, 2: \Throwable|null}
     */
    private function exchange(StationTarget $t, string $method, string $path, array $query, #[\SensitiveParameter] string $token, ?array $body, float $extra = 0.0, ?float $budget = null): array
    {
        $payload = null;
        if ($body !== null) {
            try {
                $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return [self::ANSWER, null, new \InvalidArgumentException('Request body could not be encoded as JSON: ' . $e->getMessage(), 0, $e)];
            }
        }
        if ($query !== []) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        try {
            $res = $this->transport->request($t, $method, $path, $token, $payload, $extra, $budget);
        } catch (ConnectionException $e) {
            return [self::NOT_DELIVERED, null, $e];
        } catch (NoAnswerException $e) {
            return [self::NO_ANSWER, null, $e];
        } catch (\InvalidArgumentException $e) {
            return [self::ANSWER, null, $e];
        }
        if ($res->status >= 300) {
            $e = ErrorFactory::fromResponse($res);
            if ($res->status === 502 || $res->status === 504
                || ($res->status === 503 && ($e->errorCode === 'local_disabled' || $e->errorCode === ''))) {
                return [self::NOT_DELIVERED, null, $e];
            }
            if ($res->status < 400) {
                // Not a station's answer (something on the network redirected
                // us) after the request was written: no answer.
                return [self::NO_ANSWER, null, $e];
            }

            return [self::ANSWER, null, $e];
        }
        if (trim($res->body) === '') {
            return [self::OK, [], null];
        }
        $data = json_decode($res->body, true);
        if (!is_array($data)) {
            return [self::NO_ANSWER, null, new CatzPrintException('The station returned a response the SDK couldn\'t read.', $res->status, 'invalid_response')];
        }

        return [self::OK, $data, null];
    }

    private static function isTokenRejected(?\Throwable $e): bool
    {
        return $e instanceof AuthenticationException;
    }

    /** A cloud failure that means "unreachable" (network error, timeout, 5xx), not a 4xx answer. */
    public static function cloudUnreachable(\Throwable $e): bool
    {
        return $e instanceof ConnectionException || $e instanceof ServerException;
    }

    /**
     * Answers a 401 with a fresh token, but only for token_expired (never
     * device_revoked or unauthenticated: a device revoked at the station must
     * stay revoked), only for minted tokens, and at most once per 10 minutes
     * per store key (persisted, so other PHP processes and restarts see it).
     *
     * @return array{0: string|null, 1: \Throwable|null} the new token, or why none
     */
    private function remint(\Throwable $rejected): array
    {
        if ($this->cfg->token !== '' || !$this->client->http()->hasApiKey()
            || !$rejected instanceof CatzPrintException || $rejected->errorCode !== 'token_expired') {
            return [null, null];
        }
        $now = $this->now();
        $allowed = false;
        $this->update(static function (StoredToken $st) use ($now, &$allowed): bool {
            if ($st->lastRemintAt !== null && $now - self::ts($st->lastRemintAt) < self::REMINT_INTERVAL) {
                $allowed = false;

                return false;
            }
            $st->lastRemintAt = self::dt($now);
            $allowed = true;

            return true;
        });
        if (!$allowed) {
            return [null, null];
        }
        try {
            return [$this->mint(true)->token, null];
        } catch (\Throwable $e) {
            return [null, $e];
        }
    }

    /** Token or a StationUnreachableException when the cloud can't mint one. */
    private function tokenOrUnreachable(): string
    {
        try {
            return $this->token();
        } catch (\Throwable $e) {
            if (!self::cloudUnreachable($e)) {
                throw $e;
            }

            throw new StationUnreachableException([new StationAttempt('', new ConnectionException(
                'No local token (the cloud couldn\'t mint one): ' . $e->getMessage(),
                0,
                'no_local_token',
                [],
                null,
                null,
                $e,
            ))]);
        }
    }

    /**
     * Sends a job body (the cloud jobs.create JSON) to the first station
     * that takes it.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $params the Jobs::create() params (for the job's kind)
     */
    public function createJob(array $body, array $params): Job
    {
        $tok = new Secret($this->tokenOrUnreachable());
        $this->ensureEndpoints();
        $query = $this->cfg->wait > 0 ? ['wait' => (string) $this->cfg->wait] : [];
        $attempts = [];
        $reminted = false;
        $answered = false;
        $result = null;
        $final = null;
        $key = (string) ($body['idempotency_key'] ?? '');
        $this->eachTarget('', function (StationTarget $t) use (&$tok, &$reminted, &$answered, &$result, &$final, &$attempts, $body, $query, $params, $key): bool {
            [$kind, $data, $err] = $this->exchange($t, 'POST', '/local/v1/jobs', $query, $tok->get(), $body, (float) $this->cfg->wait);
            if ($kind === self::ANSWER && self::isTokenRejected($err) && !$reminted) {
                // 401 token_expired: mint a fresh token (rate-limited) and
                // retry this station once.
                $reminted = true;
                [$nt, $merr] = $this->remint($err);
                if ($nt !== null) {
                    $tok = new Secret($nt);
                    unset($nt);
                    [$kind, $data, $err] = $this->exchange($t, 'POST', '/local/v1/jobs', $query, $tok->get(), $body, (float) $this->cfg->wait);
                } elseif ($merr !== null) {
                    $err = new AuthenticationException(
                        $err->getMessage() . ' (and a fresh token couldn\'t be minted: ' . $merr->getMessage() . ')',
                        $err instanceof CatzPrintException ? $err->status : 401,
                        $err instanceof CatzPrintException ? $err->errorCode : '',
                        [],
                        null,
                        null,
                        $err,
                    );
                }
            }
            $inProgress = false;
            if ($kind === self::ANSWER && $err instanceof CatzPrintException && $err->status === 409 && $err->errorCode === 'in_progress') {
                // The station is still processing this key (addendum 4, Q1):
                // wait for it by key, never print it anywhere else.
                $kind = self::NO_ANSWER;
                $inProgress = true;
            }
            if ($kind === self::OK && !self::looksLikeJob($data)) {
                // A 2xx without a readable job (addendum 3, C5): no answer.
                $kind = self::NO_ANSWER;
                $err = new CatzPrintException('The station answered without a job.', 0, 'invalid_response');
            }
            if ($kind === self::NO_ANSWER) {
                // The station may have taken the job: ask it by key before
                // anything else, never print it somewhere else blindly.
                [$found, $absent, $answer] = $this->probeByKey($t, $tok->get(), $key);
                if ($answer !== null) {
                    $answered = true;
                    $final = $answer; // an answer: no fallback, no next station

                    return true;
                }
                if ($found !== null) {
                    [$kind, $data, $err] = [self::OK, $found, null];
                } elseif ($absent && !$inProgress) {
                    $attempts[] = new StationAttempt($t->url, $err ?? new CatzPrintException('No answer.'));

                    return false; // never recorded: as if not delivered
                } else {
                    $answered = true;
                    $final = new OutcomeUnknownException($t->url, $key, $err);

                    return true;
                }
            }
            if ($kind === self::OK) {
                $lj = new LocalJob($data ?? [], $t->url);
                $result = self::toJob($lj, $t, $params, self::tokenLive($tok->get()));
                $this->rememberJob($lj->id, $t->url);
                $answered = true;

                return true;
            }
            if ($kind === self::NOT_DELIVERED) {
                $attempts[] = new StationAttempt($t->url, $err);

                return false;
            }
            $answered = true;
            if (self::isTokenRejected($err) && $this->cfg->mode !== \CatzPrint\Local\Mode::LOCAL) {
                // Still refused: in the fallback modes this means "use the
                // cloud"; in local mode the 401 is the answer.
                $attempts[] = new StationAttempt($t->url, $err);
                $final = new StationUnreachableException($attempts);

                return true;
            }
            $final = $err;

            return true;
        });
        if ($answered) {
            $this->maybeRefresh();
        }
        if ($result !== null) {
            return $result;
        }

        throw $final ?? new StationUnreachableException($attempts);
    }

    /** Probes after an ambiguous job request: up to 3 tries within 5 s. */
    public const PROBE_TRIES = 3;
    public const PROBE_BUDGET = 5.0;

    /**
     * After a job request was delivered without an answer: asks the same
     * station for the job by idempotency key, up to 3 times within 5 s
     * (fresh connections, same pinning).
     *
     * Outcomes (addendum 3, R3; addendum 4, Q1; addendum 6, T2): 200 -> that
     * job; 404 "not_found" -> never recorded (unknown after an "in_progress"
     * answer); 409 "duplicate" (the key belongs to a job
     * this device can't see) -> that error is the answer; anything else,
     * 409 "in_progress" included -> keep probing within the budget.
     *
     * @return array{0: array<string, mixed>|null, 1: bool, 2: \Throwable|null} [job, never recorded, answer to return]
     */
    private function probeByKey(StationTarget $t, #[\SensitiveParameter] string $tok, string $key): array
    {
        if ($key === '') {
            return [null, false, null];
        }
        $deadline = microtime(true) + self::PROBE_BUDGET;
        $sawInProgress = false;
        for ($i = 0; $i < self::PROBE_TRIES; $i++) {
            $left = $deadline - microtime(true);
            if ($left <= 0.05) {
                break;
            }
            [$kind, $data, $err] = $this->exchange($t, 'GET', '/local/v1/jobs', ['idempotency_key' => $key], $tok, null, 0.0, $left);
            if ($kind === self::OK && self::looksLikeJob($data)) {
                return [$data, false, null];
            }
            if ($kind === self::ANSWER && $err instanceof CatzPrintException && $err->status === 409 && $err->errorCode === 'in_progress') {
                $sawInProgress = true; // the station is processing this key: a later 404 isn't "never recorded"
            }
            if ($kind === self::ANSWER && $err instanceof NotFoundException && $err->errorCode === 'not_found') {
                // After an in_progress answer the outcome stays unknown (addendum 6, T2).
                return [null, !$sawInProgress, null];
            }
            if ($kind === self::ANSWER && $err instanceof CatzPrintException && $err->status === 409 && $err->errorCode === 'duplicate') {
                return [null, false, $err];
            }
            if ($i < self::PROBE_TRIES - 1) {
                usleep((int) (min(0.2, max(0.0, $deadline - microtime(true))) * 1_000_000));
            }
        }

        return [null, false, null];
    }

    /** @param array<string, mixed>|null $data */
    private static function looksLikeJob(?array $data): bool
    {
        return is_array($data) && is_string($data['id'] ?? null) && $data['id'] !== '';
    }

    /**
     * Runs $fn against each candidate station with a token until one
     * answers. $fn returns [unreachable, error]; unreachable moves on.
     *
     * @param callable(StationTarget, string): array{0: bool, 1: \Throwable|null} $fn
     */
    private function authed(string $preferred, callable $fn): void
    {
        $tok = new Secret($this->tokenOrUnreachable());
        $this->ensureEndpoints();
        $attempts = [];
        $final = null;
        $done = false;
        $reminted = false;
        $this->eachTarget($preferred, function (StationTarget $t) use (&$tok, &$attempts, &$final, &$done, &$reminted, $fn): bool {
            [$unreachable, $err] = $fn($t, $tok->get());
            if ($err !== null && !$unreachable && self::isTokenRejected($err) && !$reminted) {
                $reminted = true;
                [$nt] = $this->remint($err);
                if ($nt !== null) {
                    $tok = new Secret($nt);
                    unset($nt);
                    [$unreachable, $err] = $fn($t, $tok->get());
                }
            }
            if ($err === null) {
                $done = true;

                return true;
            }
            if ($unreachable) {
                $attempts[] = new StationAttempt($t->url, $err);

                return false;
            }
            $final = $err;

            return true;
        });
        if ($done || $final !== null) {
            $this->maybeRefresh();
        }
        if ($done) {
            return;
        }

        throw $final ?? new StationUnreachableException($attempts);
    }

    /** Status of the first station that answers. */
    public function status(): LocalStatus
    {
        $out = null;
        $this->authed('', function (StationTarget $t, #[\SensitiveParameter] string $tok) use (&$out): array {
            [$unreachable, $data, $err] = $this->send($t, 'GET', '/local/v1/status', [], $tok, null);
            if ($err === null) {
                $out = new LocalStatus($data ?? [], $t->url);
            }

            return [$unreachable, $err];
        });

        return $out ?? throw new StationUnreachableException();
    }

    /** A local job ("lj_…") from the station that has it. */
    public function getJob(string $id): Job
    {
        $pref = $this->jobUrls[$id] ?? '';
        $out = null;
        $notFound = null;
        try {
            $this->authed($pref, function (StationTarget $t, #[\SensitiveParameter] string $tok) use ($id, $pref, &$out, &$notFound): array {
                [$unreachable, $data, $err] = $this->send($t, 'GET', '/local/v1/jobs/' . rawurlencode($id), [], $tok, null);
                if ($err === null) {
                    $lj = new LocalJob($data ?? [], $t->url);
                    $out = self::toJob($lj, $t, [], self::tokenLive($tok));
                    $this->rememberJob($id, $t->url);

                    return [false, null];
                }
                if ($err instanceof NotFoundException && $pref === '') {
                    $notFound = $err; // another station may have it

                    return [true, $err];
                }

                return [$unreachable, $err];
            });
        } catch (StationUnreachableException $e) {
            throw $notFound ?? $e;
        }

        return $out ?? throw new StationUnreachableException();
    }

    private function rememberJob(string $id, string $url): void
    {
        if ($id === '') {
            return;
        }
        if (count($this->jobUrls) >= 1000) {
            $this->jobUrls = [];
        }
        $this->jobUrls[$id] = $url;
    }

    /**
     * A station's public information (no token). The URL must be trusted
     * like any station URL.
     */
    public function info(string $stationUrl): LocalInfo
    {
        $u = Net::normalizeStationUrl($stationUrl);
        $st = $this->snapshot();
        $t = $this->targetFor($u, 'config', $st, $this->mdns ?? []);
        if ($t->needsPin() && $t->fingerprint === '' && $this->cfg->discover) {
            $found = $this->browse(true);
            if ($found !== []) {
                $t = $this->targetFor($u, 'config', $st, $found);
            }
        }
        [$unreachable, $data, $err] = $this->send($t, 'GET', '/local/v1/info', [], '', null);
        if ($err !== null) {
            throw $unreachable ? new StationUnreachableException([new StationAttempt($t->url, $err)]) : $err;
        }

        return new LocalInfo($data ?? []);
    }

    /**
     * Pairs this device with one station. The station must be authenticated
     * before the code is sent: an IP URL needs the fingerprint shown in the
     * station app (or one from the "fingerprints" option, or a cloud endpoint
     * list containing that address); a hostname URL must be on the cloud
     * endpoint list. mDNS is never enough.
     */
    public function pair(string $stationUrl, #[\SensitiveParameter] string $code, ?string $deviceName, ?string $fingerprint): LocalPairing
    {
        if (strlen($code) !== 8 || !ctype_digit($code)) {
            $msg = 'The pairing code is the 8 digits shown in the station app.';

            throw new ValidationException($msg, 0, 'invalid', ['code' => $msg]);
        }
        $deviceName = trim((string) $deviceName) !== '' ? (string) $deviceName : $this->cfg->deviceName;
        $t = $this->pairTarget($stationUrl, $fingerprint);
        [$unreachable, $data, $err] = $this->send($t, 'POST', '/local/v1/pair', [], '', [
            'code' => $code,
            'device_name' => LocalConfig::truncate($deviceName, 80),
        ]);
        if ($err !== null) {
            throw $unreachable ? new StationUnreachableException([new StationAttempt($t->url, $err)]) : $err;
        }
        $p = new LocalPairing($data ?? []);
        if ($p->token === '') {
            throw new CatzPrintException('The station answered without a token.', 201, 'invalid_response');
        }

        return $p;
    }

    private function pairTarget(string $stationUrl, ?string $fp): StationTarget
    {
        $u = Net::normalizeStationUrl($stationUrl);
        [$host] = Net::urlHost($u);
        if ($fp !== null && trim($fp) !== '') {
            $norm = Net::normalizeFingerprint($fp);
            if ($norm === '') {
                $msg = 'The fingerprint is the 64 hex characters (SHA-256) shown in the station app.';

                throw new ValidationException($msg, 0, 'invalid', ['fingerprint' => $msg]);
            }
            if (!Net::isIp($host)) {
                $msg = 'Pair with the station\'s IP address URL when you pass the fingerprint from the station app.';

                throw new ValidationException($msg, 0, 'invalid', ['url' => $msg]);
            }

            return new StationTarget($u, $norm, 'self_signed', '', 'config');
        }
        $t = $this->targetFor($u, 'config', $this->snapshot(), []);
        if (($t->needsPin() && $t->fingerprint === '') || (!$t->needsPin() && $t->stationId === '')) {
            $msg = 'Pairing needs the certificate fingerprint shown in the station app, or a station on the cloud endpoint list.';

            throw new ValidationException($msg, 0, 'invalid', ['fingerprint' => $msg]);
        }

        return $t;
    }

    /**
     * Stations from the cloud endpoint list (refreshed now when the cloud
     * answers, else the cached copy) and from an mDNS browse, merged by
     * station ID.
     *
     * @return list<LocalStation>
     */
    public function discover(): array
    {
        $cloudErr = null;
        try {
            $this->refresh(true);
        } catch (\Throwable $e) {
            $cloudErr = $e;
            $this->refreshError = $e;
        }
        $st = $this->snapshot();
        $org = $this->orgHint();
        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];
        foreach ($st?->stations ?? [] as $ep) {
            if ($this->cfg->locationId !== '' && $ep->locationId !== '' && $ep->locationId !== $this->cfg->locationId) {
                continue;
            }
            $rows[$ep->stationId] = [
                'stationId' => $ep->stationId, 'name' => $ep->name, 'orgId' => $org, 'locationId' => $ep->locationId,
                'port' => $ep->port, 'addresses' => $ep->addresses,
                'urls' => array_map(static fn (StationTarget $t): string => $t->url, self::endpointTargets($ep, 'cloud')),
                'tlsMode' => $ep->tlsMode, 'fingerprint' => Net::normalizeFingerprint($ep->certFingerprintSha256),
                'fromCloud' => true, 'fromMdns' => false, 'localEnabled' => $ep->localEnabled, 'connected' => $ep->connected,
            ];
        }
        foreach ($this->acceptedMdns($this->browse(true), $st) as $d) {
            $urls = array_map(static fn (StationTarget $t): string => $t->url, self::mdnsTargets($d));
            if (isset($rows[$d->stationId])) {
                $r = &$rows[$d->stationId];
                $r['fromMdns'] = true;
                $r['addresses'] = array_values(array_unique(array_merge($r['addresses'], $d->addresses)));
                $r['urls'] = array_values(array_unique(array_merge($r['urls'], $urls)));
                unset($r);
                continue;
            }
            $rows[$d->stationId] = [
                'stationId' => $d->stationId, 'name' => $d->instance, 'orgId' => $d->orgId, 'locationId' => $d->locationId,
                'port' => $d->port, 'addresses' => $d->addresses, 'urls' => $urls, 'tlsMode' => $d->tlsMode,
                'fingerprint' => $d->fingerprint, 'fromCloud' => false, 'fromMdns' => true, 'localEnabled' => true, 'connected' => false,
            ];
        }
        if ($rows === [] && $cloudErr !== null) {
            throw $cloudErr;
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = new LocalStation(...$r);
        }

        return $out;
    }

    // ---- jobs ------------------------------------------------------------

    /** @param array<string, mixed> $p Jobs::create() params */
    public static function inferKind(array $p): string
    {
        return match (true) {
            isset($p['kind']) && $p['kind'] !== '' => (string) $p['kind'],
            isset($p['text']) => 'text',
            isset($p['commands']) => 'commands',
            isset($p['templateId']) => 'template',
            isset($p['content']) && is_string($p['content']) => self::detectKind($p['content'], (string) ($p['fileName'] ?? '')),
            default => '',
        };
    }

    /** Like Jobs::detectKind(), plus raw ESC/POS (ESC or GS first byte, no file name). */
    public static function detectKind(string $bytes, string $fileName): string
    {
        if ($bytes !== '' && ($bytes[0] === "\x1b" || $bytes[0] === "\x1d") && $fileName === '') {
            return 'escpos';
        }

        return \CatzPrint\Resource\Jobs::detectKind($bytes);
    }

    /** @param array<string, mixed> $params */
    private static function toJob(LocalJob $lj, StationTarget $t, array $params, bool $live): Job
    {
        $raw = $lj->toArray();
        $printed = $lj->status === 'printed';
        $detail = $lj->detail;
        if ($lj->status === 'failed' && $lj->errorCode !== null && $detail === '') {
            $detail = $lj->errorCode;
        }
        $kind = self::inferKind($params);

        return new Job([
            'id' => $lj->id,
            'live' => $lj->live ?? $live, // the station's answer wins
            'source' => 'local',
            'kind' => $kind,
            'status' => $lj->status,
            'status_detail' => $detail,
            'title' => $lj->title,
            'reference' => $lj->reference,
            'copies' => $lj->copies,
            'printer_id' => $lj->printerId !== '' ? $lj->printerId : null,
            'printer_name' => $lj->printerName !== '' ? $lj->printerName : null,
            'location_id' => $lj->locationId !== '' ? $lj->locationId : null,
            'station_id' => $t->stationId !== '' ? $t->stationId : null,
            'created_at' => $raw['created_at'] ?? null,
            'finished_at' => $raw['finished_at'] ?? null,
            'printed_at' => $printed ? ($raw['finished_at'] ?? null) : null,
            'printed_locally' => true,
            'idempotency_key' => $lj->idempotencyKey,
        ], null, Job::VIA_LOCAL, $lj);
    }
}

<?php

declare(strict_types=1);

namespace CatzPrint\Internal\Local;

use CatzPrint\Local\MemoryTokenStore;
use CatzPrint\Local\Mode;
use CatzPrint\Local\TokenStore;

/**
 * The validated "local" client option.
 *
 * @internal
 */
final class LocalConfig
{
    public const KEYS = [
        'mode', 'locationId', 'stationUrls', 'fingerprints', 'discover', 'allLocations', 'tokenStore', 'token',
        'deviceName', 'tokenTtlDays', 'connectTimeout', 'requestTimeout', 'cloudTimeout', 'discoverTimeout',
        'wait', 'caFile', 'autoRefresh',
    ];

    public readonly string $mode;
    public readonly string $locationId;
    /** @var list<string> normalized https://host:port */
    public readonly array $stationUrls;
    /** @var array<string, string> pin key (host:port or host) => lower-case hex fingerprint */
    public readonly array $fingerprints;
    public readonly bool $discover;
    public readonly bool $allLocations;
    public readonly TokenStore $tokenStore;
    public readonly string $token;
    public readonly string $deviceName;
    public readonly int $tokenTtlDays;
    public readonly float $connectTimeout;
    public readonly float $requestTimeout;
    public readonly float $cloudTimeout;
    public readonly float $discoverTimeout;
    /** Seconds the station may hold its answer until the job printed (0..30). */
    public readonly int $wait;
    public readonly ?string $caFile;
    public readonly bool $autoRefresh;

    /** @param array<string, mixed> $o */
    public function __construct(#[\SensitiveParameter] array $o)
    {
        $unknown = array_diff(array_keys($o), self::KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown local option(s): ' . implode(', ', $unknown) . '. Use: ' . implode(', ', self::KEYS) . '.');
        }
        $mode = (string) ($o['mode'] ?? Mode::CLOUD);
        if (!in_array($mode, Mode::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown local mode "%s" (use cloud, local, local_first or cloud_first).', $mode));
        }
        $this->mode = $mode;
        $this->locationId = trim((string) ($o['locationId'] ?? ''));

        $urls = [];
        foreach ((array) ($o['stationUrls'] ?? []) as $raw) {
            $u = Net::normalizeStationUrl((string) $raw);
            if (!in_array($u, $urls, true)) {
                $urls[] = $u;
            }
        }
        $this->stationUrls = $urls;

        $pins = [];
        foreach ((array) ($o['fingerprints'] ?? []) as $k => $v) {
            $fp = Net::normalizeFingerprint((string) $v);
            if ($fp === '') {
                throw new \InvalidArgumentException(sprintf('The fingerprint for "%s" must be 64 hex characters (SHA-256).', $k));
            }
            $pins[Net::pinKey((string) $k)] = $fp;
        }
        $this->fingerprints = $pins;

        $this->discover = (bool) ($o['discover'] ?? false);
        $this->allLocations = (bool) ($o['allLocations'] ?? false);
        $this->token = trim((string) ($o['token'] ?? ''));
        $this->autoRefresh = (bool) ($o['autoRefresh'] ?? true);

        if ($mode !== Mode::CLOUD && $urls === [] && !$this->discover) {
            throw new \InvalidArgumentException('Local printing needs "stationUrls" or "discover" => true.');
        }
        if ($mode !== Mode::CLOUD && $this->token === '' && $this->locationId === '' && !$this->allLocations) {
            throw new \InvalidArgumentException('Set the "locationId" local option (minted local tokens are bound to it), or "allLocations" => true for a token valid at every location.');
        }
        if ($this->token !== '' && preg_match('/^(czl1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+|czd_[A-Za-z0-9_-]+)$/', $this->token) !== 1) {
            throw new \InvalidArgumentException('The "token" local option must be a local token (czl1.…) or a device token (czd_…).');
        }
        $ttl = (int) ($o['tokenTtlDays'] ?? 0);
        if ($ttl < 0 || $ttl > 30) {
            throw new \InvalidArgumentException('"tokenTtlDays" must be between 1 and 30.');
        }
        $this->tokenTtlDays = $ttl;
        $wait = $o['wait'] ?? 0;
        if (!is_numeric($wait) || $wait < 0 || $wait > 30) {
            throw new \InvalidArgumentException('"wait" must be between 0 and 30 seconds.');
        }
        $this->wait = (int) ceil((float) $wait);

        $pos = static fn (string $k, float $def): float => isset($o[$k]) && is_numeric($o[$k]) && $o[$k] > 0 ? (float) $o[$k] : $def;
        $this->connectTimeout = $pos('connectTimeout', 3.0);
        $this->requestTimeout = $pos('requestTimeout', 15.0);
        $this->cloudTimeout = $pos('cloudTimeout', 10.0);
        $this->discoverTimeout = min(Mdns::MAX_TIMEOUT, $pos('discoverTimeout', Mdns::MAX_TIMEOUT));

        $store = $o['tokenStore'] ?? null;
        if ($store !== null && !$store instanceof TokenStore) {
            throw new \InvalidArgumentException('"tokenStore" must implement CatzPrint\Local\TokenStore.');
        }
        $this->tokenStore = $store ?? new MemoryTokenStore();

        $ca = $o['caFile'] ?? null;
        if ($ca !== null && (!is_string($ca) || !is_file($ca))) {
            throw new \InvalidArgumentException('"caFile" must be the path of a PEM file of CA certificates.');
        }
        $this->caFile = $ca;

        $name = trim((string) ($o['deviceName'] ?? ''));
        if ($name === '') {
            $h = gethostname();
            $name = is_string($h) && $h !== '' ? $h : 'CatzPrint PHP SDK';
        }
        $this->deviceName = self::truncate($name, 80);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $out = get_object_vars($this);
        $out['token'] = $this->token === '' ? '' : substr($this->token, 0, 4) . '…[redacted]';

        return $out;
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('The local configuration may hold a token and cannot be serialized.');
    }

    public static function truncate(string $s, int $n): string
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : (string) preg_replace('/^(.{' . $n . '}).*$/us', '$1', $s);
    }
}

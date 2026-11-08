<?php

declare(strict_types=1);

namespace CatzPrint\Local;

use CatzPrint\Model\LocalEndpoint;

/**
 * What the SDK keeps in a TokenStore for one device: its local token and the
 * last station endpoint list from the cloud, so a hub that restarts while the
 * internet is down can still find and trust its stations.
 *
 * Serialized with toArray()/fromArray() using the same JSON field names as
 * the Go SDK's token store.
 */
final class StoredToken
{
    /** The local token ("czl1…"); "" when only endpoints are stored. */
    public string $token = '';
    public string $tokenId = '';
    public ?\DateTimeImmutable $expiresAt = null;
    /** @var list<LocalEndpoint> The last endpoint list from the cloud. */
    public array $stations = [];
    public ?\DateTimeImmutable $stationsUpdatedAt = null;
    /** When a station's token_expired last caused a re-mint (at most once per 10 minutes). */
    public ?\DateTimeImmutable $lastRemintAt = null;
    /**
     * When the next lazy refresh may be tried after one failed (PHP only:
     * there is no long-lived process to remember it).
     */
    public ?\DateTimeImmutable $refreshRetryAt = null;

    /**
     * Whether $token is a redaction placeholder rather than a token: "…", a
     * run of 3+ "*", "[redacted]", "redacted" or "hidden" (any case),
     * optionally after "czl1." or "czd_". Every CatzPrint SDK recognises the
     * same set; such a stored token counts as "no token".
     */
    public static function isRedactionPlaceholder(#[\SensitiveParameter] string $token): bool
    {
        return preg_match('/^(?:czl1\.|czd_)?(?:…|\*{3,}|\[redacted\]|redacted|hidden)$/iu', $token) === 1;
    }

    /** Whether the token can be used at $now (unix seconds): set and more than a minute left. */
    public function usable(float $now): bool
    {
        return $this->token !== '' && $this->expiresAt !== null && $now + 60 < (float) $this->expiresAt->format('U.u');
    }

    /** Whether the cloud endpoint list was ever fetched. */
    public function endpointsKnown(): bool
    {
        return $this->stationsUpdatedAt !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [];
        if ($this->token !== '') {
            $out['token'] = $this->token;
        }
        if ($this->tokenId !== '') {
            $out['token_id'] = $this->tokenId;
        }
        $fmt = static fn (?\DateTimeImmutable $d): ?string => $d?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        foreach ([
            'expires_at' => $this->expiresAt,
            'stations_updated_at' => $this->stationsUpdatedAt,
            'last_remint_at' => $this->lastRemintAt,
            'refresh_retry_at' => $this->refreshRetryAt,
        ] as $k => $d) {
            if ($d !== null) {
                $out[$k] = $fmt($d);
            }
        }
        if ($this->stations !== []) {
            $out['stations'] = array_map(static fn (LocalEndpoint $e): array => $e->toArray(), $this->stations);
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $t = new self();
        $t->token = is_string($data['token'] ?? null) ? $data['token'] : '';
        $t->tokenId = is_string($data['token_id'] ?? null) ? $data['token_id'] : '';
        $t->expiresAt = self::date($data['expires_at'] ?? null);
        $t->stations = LocalEndpoint::listFrom($data['stations'] ?? []);
        $t->stationsUpdatedAt = self::date($data['stations_updated_at'] ?? null);
        $t->lastRemintAt = self::date($data['last_remint_at'] ?? null);
        $t->refreshRetryAt = self::date($data['refresh_retry_at'] ?? null);

        return $t;
    }

    private static function date(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || $v === '' || str_starts_with($v, '0001-01-01')) {
            return null; // Go writes the zero time for "unset"
        }
        $v = (string) preg_replace('/(\.\d{6})\d+/', '$1', $v);
        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $a = $this->toArray();
        if (isset($a['token'])) {
            $a['token'] = substr($this->token, 0, 5) . '…';
        }

        return $a;
    }
}

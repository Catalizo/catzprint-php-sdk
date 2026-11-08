<?php

declare(strict_types=1);

namespace CatzPrint\Internal\Local;

/**
 * URL, address and fingerprint helpers for local printing.
 *
 * @internal
 */
final class Net
{
    /**
     * Checks a station URL (https only, no credentials, path, query or
     * fragment) and returns "https://host:port" in lower case.
     */
    public static function normalizeStationUrl(string $raw): string
    {
        $raw = trim($raw);
        $p = parse_url($raw);
        if (!is_array($p) || !isset($p['host']) || $p['host'] === '') {
            throw new \InvalidArgumentException(sprintf('Station URL "%s" isn\'t a valid URL.', $raw));
        }
        if (strtolower($p['scheme'] ?? '') !== 'https') {
            throw new \InvalidArgumentException(sprintf('Station URL "%s" must use https://.', $raw));
        }
        $path = $p['path'] ?? '';
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || ($path !== '' && $path !== '/')
            || str_contains($raw, '?') || str_contains($raw, '#') || str_contains($raw, '@')) {
            throw new \InvalidArgumentException(sprintf('Station URL "%s" must be just https://host:port.', $raw));
        }
        $host = strtolower($p['host']);
        if (str_starts_with($host, '[')) {
            $inner = trim($host, '[]');
            if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new \InvalidArgumentException(sprintf('Station URL "%s" isn\'t a valid URL.', $raw));
            }
        } elseif (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
            throw new \InvalidArgumentException(sprintf('Station URL "%s" isn\'t a valid URL.', $raw));
        }

        return 'https://' . $host . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /**
     * Accepts hex with optional colons/spaces/dashes and returns lower-case
     * hex, or "" when it isn't 32 bytes of hex.
     */
    public static function normalizeFingerprint(string $fp): string
    {
        $fp = strtolower(str_replace([':', ' ', '-'], '', trim($fp)));

        return strlen($fp) === 64 && ctype_xdigit($fp) ? $fp : '';
    }

    /** Normalizes a "fingerprints" key: a URL becomes host:port, anything else is lower-cased. */
    public static function pinKey(string $k): string
    {
        $k = strtolower(trim($k));
        if (str_contains($k, '://')) {
            $p = parse_url($k);
            if (is_array($p) && isset($p['host'])) {
                return $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            }
        }

        return rtrim($k, '/');
    }

    /**
     * Host (lower case, IPv6 without brackets) and port ("" when none) of a URL.
     *
     * @return array{0: string, 1: string}
     */
    public static function urlHost(string $url): array
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['host'])) {
            return ['', ''];
        }

        return [strtolower(trim($p['host'], '[]')), isset($p['port']) ? (string) $p['port'] : ''];
    }

    public static function isIp(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Whether $h is an IP a station may use on the LAN: RFC 1918, IPv6 ULA
     * (fc00::/7), link-local, or loopback. Anything else from the cloud list
     * or mDNS is ignored (100.64.0.0/10 is not private).
     */
    public static function privateIp(string $h): bool
    {
        $bin = self::ipBytes($h);
        if ($bin === null) {
            return false;
        }
        if (strlen($bin) === 4) {
            return self::private4($bin, true);
        }
        $b = array_values(unpack('C*', $bin) ?: []);

        return ($b[0] & 0xfe) === 0xfc                       // fc00::/7
            || ($b[0] === 0xfe && ($b[1] & 0xc0) === 0x80)   // fe80::/10
            || $bin === str_repeat("\0", 15) . "\1";         // ::1
    }

    /**
     * Decodes the station hostname scheme "<a>-<b>-<c>-<d>.<label>.<zone>"
     * to the private (or loopback) IPv4 it names, only for names under the
     * given label (from the cloud endpoint list). Returns null otherwise.
     */
    public static function dashedIp(string $host, string $label): ?string
    {
        $labels = explode('.', strtolower($host));
        if (count($labels) < 3 || $label === '' || $labels[1] !== strtolower($label)) {
            return null;
        }
        $parts = explode('-', $labels[0]);
        if (count($parts) !== 4) {
            return null;
        }
        foreach ($parts as $part) {
            if ($part === '' || !ctype_digit($part) || strlen($part) > 3 || ($part[0] === '0' && $part !== '0') || (int) $part > 255) {
                return null;
            }
        }
        $ip = implode('.', $parts);
        $bin = self::ipBytes($ip);

        return $bin !== null && strlen($bin) === 4 && self::private4($bin, false) ? $ip : null;
    }

    /** Packed address (4 bytes for IPv4 and IPv4-mapped IPv6, else 16), or null. */
    private static function ipBytes(string $h): ?string
    {
        $h = trim($h, '[]');
        if (filter_var($h, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = @inet_pton($h);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($bin, 12);
        }

        return $bin;
    }

    private static function private4(string $bin, bool $linkLocal): bool
    {
        [$a, $b] = array_values(unpack('C2', $bin) ?: [0, 0]);

        return $a === 10
            || ($a === 172 && ($b & 0xf0) === 16)
            || ($a === 192 && $b === 168)
            || $a === 127
            || ($linkLocal && $a === 169 && $b === 254);
    }
}

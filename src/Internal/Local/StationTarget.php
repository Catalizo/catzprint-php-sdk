<?php

declare(strict_types=1);

namespace CatzPrint\Internal\Local;

/**
 * One station URL to try, with how to trust it.
 *
 * @internal
 */
final class StationTarget
{
    /**
     * @param string $url         https://host:port, no trailing slash
     * @param string $fingerprint lower-case hex SHA-256 of the certificate DER, or ""
     * @param string $tlsMode     public, self_signed or "" (unknown)
     * @param string $source      config, cloud or mdns
     * @param string $spki        hex SHA-256 of the public certificate's SubjectPublicKeyInfo,
     *                            pinned on top of normal verification for public hostnames
     * @param string $label       the station's local label: a dashed-IP hostname is dialled
     *                            directly only under it ("<a-b-c-d>.<label>.<zone>")
     */
    public function __construct(
        public readonly string $url,
        public readonly string $fingerprint = '',
        public readonly string $tlsMode = '',
        public readonly string $stationId = '',
        public readonly string $source = 'config',
        public readonly string $spki = '',
        public readonly string $label = '',
    ) {
    }

    /**
     * Whether the URL can only be trusted by fingerprint: raw IP addresses,
     * mDNS .local names and stations known to be self-signed. Public
     * hostnames are verified with the CA roots like any website.
     */
    public function needsPin(): bool
    {
        [$host] = Net::urlHost($this->url);

        return $host === '' || Net::isIp($host) || str_ends_with($host, '.local') || $this->tlsMode === 'self_signed';
    }
}

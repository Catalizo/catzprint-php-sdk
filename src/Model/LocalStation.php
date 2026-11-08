<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** A station found by Local::discover(). */
final class LocalStation
{
    /**
     * @param list<string> $addresses
     * @param list<string> $urls       The URLs the SDK would use, in order.
     * @param bool         $fromCloud  In the cloud's (authenticated) endpoint list.
     * @param bool         $fromMdns   Answered on the local network.
     * @param bool         $localEnabled From the cloud endpoint list (true for mDNS-only stations).
     * @param bool         $connected  From the cloud endpoint list.
     */
    public function __construct(
        public readonly string $stationId,
        public readonly string $name,
        public readonly string $orgId,
        public readonly string $locationId,
        public readonly int $port,
        public readonly array $addresses,
        public readonly array $urls,
        public readonly string $tlsMode,
        public readonly string $fingerprint,
        public readonly bool $fromCloud,
        public readonly bool $fromMdns,
        public readonly bool $localEnabled,
        public readonly bool $connected,
    ) {
    }
}

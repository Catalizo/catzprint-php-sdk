<?php

declare(strict_types=1);

namespace CatzPrint\Internal\Local;

/**
 * One station as announced over mDNS. The announced "host" TXT value is
 * deliberately not kept: hostnames come only from the cloud endpoint list.
 *
 * @internal
 */
final class MdnsStation
{
    /** @param list<string> $addresses */
    public function __construct(
        public readonly string $instance,
        public readonly string $stationId,
        public readonly string $orgId,
        public readonly string $locationId,
        public readonly int $port,
        public readonly string $fingerprint,
        public readonly string $tlsMode,
        public readonly array $addresses,
    ) {
    }

    /** @param list<string> $addresses */
    public function with(array $addresses, string $fingerprint, string $tlsMode, int $port): self
    {
        return new self($this->instance, $this->stationId, $this->orgId, $this->locationId, $port, $fingerprint, $tlsMode, $addresses);
    }
}

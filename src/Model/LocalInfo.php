<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** A station's unauthenticated GET /local/v1/info answer. */
final class LocalInfo extends Model
{
    public readonly string $stationId;
    public readonly string $name;
    public readonly string $orgId;
    public readonly string $locationId;
    public readonly string $version;
    public readonly int $port;
    public readonly string $tlsMode;
    public readonly string $certFingerprintSha256;
    /** @var list<string> */
    public readonly array $hostnames;
    /** @var list<string> */
    public readonly array $addresses;
    public readonly bool $pairingOpen;
    public readonly ?\DateTimeImmutable $time;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->stationId = self::str($data, 'station_id');
        $this->name = self::str($data, 'name');
        $this->orgId = self::str($data, 'org_id');
        $this->locationId = self::str($data, 'location_id');
        $this->version = self::str($data, 'version');
        $this->port = self::int($data, 'port');
        $this->tlsMode = self::str($data, 'tls_mode');
        $this->certFingerprintSha256 = self::str($data, 'cert_fingerprint_sha256');
        $this->hostnames = self::strList($data, 'hostnames');
        $this->addresses = self::strList($data, 'addresses');
        $this->pairingOpen = self::bool($data, 'pairing_open');
        $this->time = self::date($data, 'time');
    }
}

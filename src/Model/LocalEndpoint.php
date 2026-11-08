<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/**
 * One station's local-network address as the cloud knows it
 * (GET /v1/orgs/{org}/local-endpoints and the local-tokens response).
 */
final class LocalEndpoint extends Model
{
    public readonly string $stationId;
    public readonly string $name;
    public readonly string $locationId;
    public readonly bool $connected;
    public readonly bool $localEnabled;
    public readonly int $port;
    /** @var list<string> The station's private addresses. */
    public readonly array $addresses;
    /** @var list<string> Public-certificate hostnames first, then raw IP URLs. */
    public readonly array $urls;
    /** "public" (publicly trusted certificate for the hostnames) or "self_signed". */
    public readonly string $tlsMode;
    /** Hex SHA-256 of the station's certificate (DER); pinned for IP and self-signed URLs. */
    public readonly string $certFingerprintSha256;
    /** Hex SHA-256 of the public certificate's SubjectPublicKeyInfo; pinned for hostnames. */
    public readonly string $publicSpkiSha256;
    /** The station's label in "<a-b-c-d>.<label>.<zone>". */
    public readonly string $localLabel;
    public readonly ?\DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->stationId = self::str($data, 'station_id');
        $this->name = self::str($data, 'name');
        $this->locationId = self::str($data, 'location_id');
        $this->connected = self::bool($data, 'connected');
        $this->localEnabled = self::bool($data, 'local_enabled');
        $this->port = self::int($data, 'port');
        $this->addresses = self::strList($data, 'addresses');
        $this->urls = self::strList($data, 'urls');
        $this->tlsMode = self::str($data, 'tls_mode');
        $this->certFingerprintSha256 = self::str($data, 'cert_fingerprint_sha256');
        $this->publicSpkiSha256 = self::str($data, 'public_spki_sha256');
        $this->localLabel = self::str($data, 'local_label');
        $this->updatedAt = self::date($data, 'updated_at');
    }

    /**
     * @param mixed $list
     *
     * @return list<LocalEndpoint>
     */
    public static function listFrom(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_array($item)) {
                $out[] = new self($item);
            }
        }

        return $out;
    }
}

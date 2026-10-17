<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** A printer attached to a station. */
final class Printer extends Model
{
    public readonly string $id;
    public readonly string $stationId;
    public readonly string $stationName;
    public readonly ?string $locationId;
    public readonly string $deviceKey;
    public readonly string $name;
    /** receipt, kitchen, bar, label, office or other. */
    public readonly string $role;
    public readonly string $connection;
    public readonly string $address;
    /** 80mm, 58mm, a4, letter or label. */
    public readonly string $paper;
    /** @var list<string> e.g. ["escpos"] or ["pdf"]. */
    public readonly array $capabilities;
    public readonly bool $enabled;
    public readonly string $status;
    public readonly string $statusDetail;
    public readonly bool $sharedWithIndividuals;
    /** Station and its location are both enabled. */
    public readonly bool $stationEnabled;
    public readonly bool $stationConnected;
    public readonly ?\DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id = self::str($data, 'id');
        $this->stationId = self::str($data, 'station_id');
        $this->stationName = self::str($data, 'station_name');
        $this->locationId = self::strOrNull($data, 'location_id');
        $this->deviceKey = self::str($data, 'device_key');
        $this->name = self::str($data, 'name');
        $this->role = self::str($data, 'role');
        $this->connection = self::str($data, 'connection');
        $this->address = self::str($data, 'address');
        $this->paper = self::str($data, 'paper');
        $this->capabilities = self::strList($data, 'capabilities');
        $this->enabled = self::bool($data, 'enabled');
        $this->status = self::str($data, 'status');
        $this->statusDetail = self::str($data, 'status_detail');
        $this->sharedWithIndividuals = self::bool($data, 'shared_with_individuals');
        $this->stationEnabled = self::bool($data, 'station_enabled');
        $this->stationConnected = self::bool($data, 'station_connected');
        $this->updatedAt = self::date($data, 'updated_at');
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}

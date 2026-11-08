<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/**
 * The result of Local::pair(): a device token ("czd_…") for that one station.
 * Pass it as the "token" local option.
 */
final class LocalPairing extends Model
{
    public readonly string $deviceId;
    public readonly string $token;
    public readonly string $stationId;
    public readonly string $orgId;
    public readonly string $locationId;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->deviceId = self::str($data, 'device_id');
        $this->token = self::str($data, 'token');
        $this->stationId = self::str($data, 'station_id');
        $this->orgId = self::str($data, 'org_id');
        $this->locationId = self::str($data, 'location_id');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['deviceId' => $this->deviceId, 'token' => 'czd_…', 'stationId' => $this->stationId];
    }
}

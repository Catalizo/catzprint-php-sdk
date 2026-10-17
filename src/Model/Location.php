<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** A place with stations and printers (a shop, a kitchen, an office). */
final class Location extends Model
{
    public readonly string $id;
    public readonly string $name;
    public readonly string $address;
    public readonly string $timeZone;
    public readonly bool $enabled;
    public readonly ?\DateTimeImmutable $disabledAt;
    /** hold, fallback or reject. */
    public readonly string $disabledJobPolicy;
    public readonly ?string $fallbackLocationId;
    public readonly int $stations;
    public readonly int $printers;
    public readonly ?\DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id = self::str($data, 'id');
        $this->name = self::str($data, 'name');
        $this->address = self::str($data, 'address');
        $this->timeZone = self::str($data, 'time_zone');
        $this->enabled = self::bool($data, 'enabled');
        $this->disabledAt = self::date($data, 'disabled_at');
        $this->disabledJobPolicy = self::str($data, 'disabled_job_policy');
        $this->fallbackLocationId = self::strOrNull($data, 'fallback_location_id');
        $this->stations = self::int($data, 'stations');
        $this->printers = self::int($data, 'printers');
        $this->createdAt = self::date($data, 'created_at');
    }
}

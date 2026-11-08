<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/**
 * A station's GET /local/v1/status answer: the station, its printers, jobs
 * waiting, jobs not yet reported to the cloud and the offline allowance.
 */
final class LocalStatus extends Model
{
    public readonly string $stationId;
    public readonly string $stationName;
    /** Whether the station itself currently reaches the cloud. */
    public readonly bool $cloudConnected;
    public readonly ?\DateTimeImmutable $lastCloudContact;
    public readonly string $version;
    public readonly bool $localEnabled;
    /** @var list<LocalPrinterStatus> */
    public readonly array $printers;
    public readonly int $jobsWaiting;
    /** Local jobs the station still has to report to the cloud. */
    public readonly int $unsynced;
    /** When the offline allowance ends (null: none yet). */
    public readonly ?\DateTimeImmutable $allowanceExpiresAt;
    /** Copies the station may still print offline. */
    public readonly int $allowancePrintsLeft;
    /** The station URL that answered (set by the SDK). */
    public readonly string $stationUrl;

    /** @param array<string, mixed> $data */
    public function __construct(array $data, string $stationUrl = '')
    {
        parent::__construct($data);
        $station = is_array($data['station'] ?? null) ? $data['station'] : [];
        $allowance = is_array($data['allowance'] ?? null) ? $data['allowance'] : [];
        $this->stationId = self::str($station, 'id');
        $this->stationName = self::str($station, 'name');
        $this->cloudConnected = self::bool($station, 'cloud_connected');
        $this->lastCloudContact = self::date($station, 'last_cloud_contact');
        $this->version = self::str($station, 'version');
        $this->localEnabled = self::bool($station, 'local_enabled');
        $printers = [];
        foreach (is_array($data['printers'] ?? null) ? $data['printers'] : [] as $p) {
            if (is_array($p)) {
                $printers[] = new LocalPrinterStatus($p);
            }
        }
        $this->printers = $printers;
        $this->jobsWaiting = self::int($data, 'jobs_waiting');
        $this->unsynced = self::int($data, 'unsynced');
        $this->allowanceExpiresAt = self::date($allowance, 'expires_at');
        $this->allowancePrintsLeft = self::int($allowance, 'prints_left');
        $this->stationUrl = $stationUrl;
    }
}

<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** A job as a station's local API reports it (ids "lj_…"). */
final class LocalJob extends Model
{
    public readonly string $id;
    public readonly string $idempotencyKey;
    /** queued, printing, printed or failed. */
    public readonly string $status;
    /** Why it failed (e.g. out_of_paper), when it did. */
    public readonly ?string $errorCode;
    public readonly string $detail;
    public readonly string $printerId;
    public readonly string $printerName;
    public readonly string $role;
    public readonly string $locationId;
    public readonly int $copies;
    public readonly string $title;
    public readonly string $reference;
    public readonly ?\DateTimeImmutable $createdAt;
    public readonly ?\DateTimeImmutable $finishedAt;
    public readonly bool $printedLocally;
    public readonly string $via;
    public readonly ?string $cloudJobId;
    /** False for jobs sent with a test-mode token; null when the station didn't say. */
    public readonly ?bool $live;
    /** The station URL that answered (set by the SDK). */
    public readonly string $stationUrl;

    /** @param array<string, mixed> $data */
    public function __construct(array $data, string $stationUrl = '')
    {
        parent::__construct($data);
        $this->id = self::str($data, 'id');
        $this->idempotencyKey = self::str($data, 'idempotency_key');
        $this->status = self::str($data, 'status');
        $this->errorCode = self::strOrNull($data, 'error_code') ?: null;
        $this->detail = self::str($data, 'detail');
        $this->printerId = self::str($data, 'printer_id');
        $this->printerName = self::str($data, 'printer_name');
        $this->role = self::str($data, 'role');
        $this->locationId = self::str($data, 'location_id');
        $this->copies = self::int($data, 'copies');
        $this->title = self::str($data, 'title');
        $this->reference = self::str($data, 'reference');
        $this->createdAt = self::date($data, 'created_at');
        $this->finishedAt = self::date($data, 'finished_at');
        $this->printedLocally = self::bool($data, 'printed_locally');
        $this->via = self::str($data, 'via');
        $this->cloudJobId = self::strOrNull($data, 'cloud_job_id') ?: null;
        $this->live = isset($data['live']) && is_bool($data['live']) ? $data['live'] : null;
        $this->stationUrl = $stationUrl;
    }
}

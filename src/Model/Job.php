<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/**
 * A print job.
 *
 * Statuses: scheduled, held, queued, sent, printing, printed, failed, cancelled.
 */
final class Job extends Model
{
    public const FINAL_STATUSES = ['printed', 'failed', 'cancelled'];

    public readonly string $id;
    /** False for test jobs (test keys); those are never billed. */
    public readonly bool $live;
    /** api, console, app, playground, share, station… */
    public readonly string $source;
    /** escpos, text, pdf, image… (what the station prints). */
    public readonly string $kind;
    public readonly string $status;
    /** Plain-English detail, e.g. why it failed or is held. */
    public readonly string $statusDetail;
    public readonly string $title;
    public readonly string $reference;
    public readonly int $copies;
    public readonly ?string $printerId;
    public readonly ?string $printerName;
    public readonly ?string $stationId;
    public readonly ?string $stationName;
    public readonly ?string $locationId;
    public readonly ?string $locationName;
    /** Content size in bytes. */
    public readonly int $size;
    public readonly int $attempts;
    /** "test" for test jobs; otherwise how the print was paid, e.g. "plan", "free", "exempt", "org_allowance". */
    public readonly string $billedAs;
    public readonly ?string $createdBy;
    public readonly ?string $reprintOf;
    public readonly ?\DateTimeImmutable $scheduledFor;
    public readonly ?\DateTimeImmutable $createdAt;
    public readonly ?\DateTimeImmutable $sentAt;
    public readonly ?\DateTimeImmutable $printedAt;
    public readonly ?\DateTimeImmutable $finishedAt;
    /** @var list<JobEvent>|null Status history; only set by Jobs::get(). */
    public readonly ?array $events;

    /**
     * @param array<string, mixed>             $data
     * @param list<array<string, mixed>>|null  $events
     */
    public function __construct(array $data, ?array $events = null)
    {
        parent::__construct($data);
        $this->id = self::str($data, 'id');
        $this->live = self::bool($data, 'live');
        $this->source = self::str($data, 'source');
        $this->kind = self::str($data, 'kind');
        $this->status = self::str($data, 'status');
        $this->statusDetail = self::str($data, 'status_detail');
        $this->title = self::str($data, 'title');
        $this->reference = self::str($data, 'reference');
        $this->copies = self::int($data, 'copies');
        $this->printerId = self::strOrNull($data, 'printer_id');
        $this->printerName = self::strOrNull($data, 'printer_name');
        $this->stationId = self::strOrNull($data, 'station_id');
        $this->stationName = self::strOrNull($data, 'station_name');
        $this->locationId = self::strOrNull($data, 'location_id');
        $this->locationName = self::strOrNull($data, 'location_name');
        $this->size = self::int($data, 'size');
        $this->attempts = self::int($data, 'attempts');
        $this->billedAs = self::str($data, 'billed_as');
        $this->createdBy = self::strOrNull($data, 'created_by');
        $this->reprintOf = self::strOrNull($data, 'reprint_of');
        $this->scheduledFor = self::date($data, 'scheduled_for');
        $this->createdAt = self::date($data, 'created_at');
        $this->sentAt = self::date($data, 'sent_at');
        $this->printedAt = self::date($data, 'printed_at');
        $this->finishedAt = self::date($data, 'finished_at');
        $this->events = $events === null ? null : array_values(array_map(
            static fn (array $e): JobEvent => new JobEvent($e),
            array_filter($events, 'is_array'),
        ));
    }

    /** True once the job is printed, failed or cancelled. */
    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    public function isPrinted(): bool
    {
        return $this->status === 'printed';
    }
}

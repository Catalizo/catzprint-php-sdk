<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/**
 * A webhook event: {"id", "type", "created_at", "org_id", "data"}.
 *
 * $id is the delivery id, identical across retries of one delivery.
 * Deduplicate on it: the timestamp check only limits the replay window to
 * the tolerance (5 minutes by default). Null when the server sent none.
 *
 * Types: job.created, job.printed, job.failed, job.cancelled, job.retried,
 * station.online, station.offline, printer.status.
 */
final class Event extends Model
{
    public readonly ?string $id;
    public readonly string $type;
    public readonly ?\DateTimeImmutable $createdAt;
    public readonly string $orgId;
    /** @var array<string, mixed> */
    public readonly array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id = self::strOrNull($data, 'id');
        $this->type = self::str($data, 'type');
        $this->createdAt = self::date($data, 'created_at');
        $this->orgId = self::str($data, 'org_id');
        $this->data = is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    /** The job in a job.* event, or null. */
    public function job(): ?Job
    {
        $j = $this->data['job'] ?? null;

        return is_array($j) ? new Job($j) : null;
    }
}

<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** One printer in LocalStatus. */
final class LocalPrinterStatus extends Model
{
    public readonly string $id;
    public readonly string $name;
    public readonly string $role;
    /** ready, offline, paper_out, paper_low, cover_open, error, busy or unknown. */
    public readonly string $status;
    /** ok, low, out or unknown. */
    public readonly string $paper;
    public readonly int $jobsWaiting;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id = self::str($data, 'id');
        $this->name = self::str($data, 'name');
        $this->role = self::str($data, 'role');
        $this->status = self::str($data, 'status');
        $this->paper = self::str($data, 'paper');
        $this->jobsWaiting = self::int($data, 'jobs_waiting');
    }
}

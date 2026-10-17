<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** One step in a job's history (from Jobs::get()). */
final class JobEvent extends Model
{
    public readonly ?\DateTimeImmutable $at;
    public readonly string $status;
    public readonly string $detail;
    /** Who did it: an email, "API key", "Station" or "System". */
    public readonly string $actor;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->at = self::date($data, 'at');
        $this->status = self::str($data, 'status');
        $this->detail = self::str($data, 'detail');
        $this->actor = self::str($data, 'actor');
    }
}

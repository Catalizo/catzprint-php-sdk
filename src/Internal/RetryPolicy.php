<?php

declare(strict_types=1);

namespace CatzPrint\Internal;

/**
 * Exponential backoff with jitter.
 *
 * Attempt n (0-based) waits between half and all of min(maxDelay, baseDelay * 2^n)
 * seconds. When the API names a wait (Retry-After / retry_after_seconds) that wait
 * is used instead, as long as it is no longer than $maxRetryAfter.
 *
 * @internal
 */
final class RetryPolicy
{
    /** @var callable(): float */
    private $random;

    /**
     * @param int   $maxRetries    Retries after the first attempt (default 2).
     * @param float $baseDelay     Seconds before the first retry, before jitter.
     * @param float $maxDelay      Upper bound for one computed backoff.
     * @param float $maxRetryAfter Longest server-requested wait we are willing to sleep.
     * @param (callable(): float)|null $random Returns a float in [0, 1); injectable for tests.
     */
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly float $baseDelay = 0.5,
        public readonly float $maxDelay = 8.0,
        public readonly float $maxRetryAfter = 60.0,
        ?callable $random = null,
    ) {
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /** HTTP statuses worth retrying. */
    public static function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status === 502 || $status === 503 || $status === 504;
    }

    /**
     * Seconds to wait before retry number $attempt + 1, or null to give up.
     */
    public function delay(int $attempt, ?float $retryAfter = null): ?float
    {
        if ($attempt >= $this->maxRetries) {
            return null;
        }
        if ($retryAfter !== null && $retryAfter > 0) {
            return $retryAfter <= $this->maxRetryAfter ? $retryAfter : null;
        }
        $cap = min($this->maxDelay, $this->baseDelay * (2 ** $attempt));
        $r = ($this->random)();

        return $cap / 2 + $cap / 2 * $r;
    }
}

<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** One station URL the SDK tried, and why it couldn't be used. */
final class StationAttempt
{
    /**
     * @param string     $url   The station URL ("" when no station was tried, e.g. no token could be minted).
     * @param \Throwable $error Why it failed (ConnectionException, PinMismatchException, an API error…).
     */
    public function __construct(
        public readonly string $url,
        public readonly \Throwable $error,
    ) {
    }
}

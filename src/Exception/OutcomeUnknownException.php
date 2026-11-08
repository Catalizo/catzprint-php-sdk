<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/**
 * A job request reached a station (or the CatzPrint API) completely, but no
 * answer came back and asking again by idempotency key didn't settle it: the
 * job may have printed. The SDK does not fall back anywhere else, so it can
 * never print twice. Send it again later with the same idempotency key: that
 * is always safe (the station and the cloud both dedupe on it).
 */
class OutcomeUnknownException extends CatzPrintException
{
    public const MESSAGE = 'The station may have printed this job. Retry later with the same idempotency key.';

    /**
     * @param string          $url            the station URL (or the API base URL for the cloud)
     * @param string          $idempotencyKey the key to retry with
     * @param \Throwable|null $cause          the last failure (also getPrevious())
     */
    public function __construct(
        public readonly string $url,
        public readonly string $idempotencyKey,
        public readonly ?\Throwable $cause = null,
    ) {
        parent::__construct(self::MESSAGE, 0, 'outcome_unknown', [], null, null, $cause);
    }
}

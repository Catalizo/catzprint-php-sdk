<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/**
 * Base class for every error the SDK throws.
 *
 * API errors carry the HTTP status, the API's stable machine-readable
 * error code (e.g. "invalid", "out_of_prints"), per-field messages for
 * validation errors and, for rate limits, the seconds to wait.
 */
class CatzPrintException extends \RuntimeException
{
    /** HTTP status, or 0 when the request never got an answer. */
    public readonly int $status;

    /** Stable error code from the API, e.g. "not_found". */
    public readonly string $errorCode;

    /** @var array<string, string> Field name => message (validation errors). */
    public readonly array $fields;

    /** Seconds to wait before retrying, when the API said so. */
    public readonly ?int $retryAfter;

    /** X-Request-Id of the failed request, handy for support. */
    public readonly ?string $requestId;

    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        string $message,
        int $status = 0,
        string $errorCode = '',
        array $fields = [],
        ?int $retryAfter = null,
        ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->fields = $fields;
        $this->retryAfter = $retryAfter;
        $this->requestId = $requestId;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, string> */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    // ---- local printing helpers ------------------------------------------

    /** A station refused the job because the routed printer is offline. */
    public function isPrinterOffline(): bool
    {
        return $this->errorCode === 'printer_offline';
    }

    /** A station refused (or failed) the job because the printer is out of paper. */
    public function isOutOfPaper(): bool
    {
        return $this->errorCode === 'out_of_paper';
    }

    /** The printer's cover is open. */
    public function isCoverOpen(): bool
    {
        return $this->errorCode === 'cover_open';
    }

    /**
     * The station has been offline too long or has used its offline prints
     * (402 offline_allowance_exhausted). Connect it to the internet.
     */
    public function isOfflineAllowanceExhausted(): bool
    {
        return $this->errorCode === 'offline_allowance_exhausted';
    }

    /** No station could be reached (a StationUnreachableException). */
    public function isStationUnreachable(): bool
    {
        return $this instanceof StationUnreachableException;
    }

    /**
     * A job request was delivered but its outcome is unknown (it may have
     * printed). Retry later with the same idempotency key.
     */
    public function isOutcomeUnknown(): bool
    {
        return $this instanceof OutcomeUnknownException;
    }
}

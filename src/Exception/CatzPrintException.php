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
}

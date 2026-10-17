<?php

declare(strict_types=1);

namespace CatzPrint\Internal;

use CatzPrint\Exception\AuthenticationException;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ConflictException;
use CatzPrint\Exception\NotFoundException;
use CatzPrint\Exception\OutOfPrintsException;
use CatzPrint\Exception\PermissionException;
use CatzPrint\Exception\RateLimitException;
use CatzPrint\Exception\ServerException;
use CatzPrint\Exception\ValidationException;

/**
 * Maps the API's {"error": {"code", "message", "fields", "retry_after_seconds"}}
 * body to the right exception class.
 *
 * @internal
 */
final class ErrorFactory
{
    public static function fromResponse(Response $res): CatzPrintException
    {
        $code = '';
        $message = '';
        $fields = [];
        $retryAfter = null;

        $decoded = json_decode($res->body, true);
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $e = $decoded['error'];
            $code = is_string($e['code'] ?? null) ? $e['code'] : '';
            $message = is_string($e['message'] ?? null) ? $e['message'] : '';
            if (isset($e['fields']) && is_array($e['fields'])) {
                foreach ($e['fields'] as $k => $v) {
                    $fields[(string) $k] = is_scalar($v) ? (string) $v : (string) json_encode($v);
                }
            }
            if (isset($e['retry_after_seconds']) && is_numeric($e['retry_after_seconds'])) {
                $retryAfter = (int) $e['retry_after_seconds'];
            }
        }
        if ($retryAfter === null) {
            $retryAfter = self::parseRetryAfter($res->header('Retry-After'));
        }
        if ($message === '') {
            $message = sprintf('CatzPrint API answered HTTP %d.', $res->status);
        }

        return self::make($res->status, $code, $message, $fields, $retryAfter, $res->header('X-Request-Id'));
    }

    /**
     * @param array<string, string> $fields
     */
    public static function make(
        int $status,
        string $code,
        string $message,
        array $fields = [],
        ?int $retryAfter = null,
        ?string $requestId = null,
    ): CatzPrintException {
        $class = match (true) {
            $code === 'out_of_prints' || $status === 402 => OutOfPrintsException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 400 || $status === 422 => ValidationException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => CatzPrintException::class,
        };

        return new $class($message, $status, $code, $fields, $retryAfter, $requestId);
    }

    /** Parses a Retry-After header (delta-seconds or HTTP date) into seconds. */
    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return max(0, $ts - time());
    }
}

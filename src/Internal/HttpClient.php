<?php

declare(strict_types=1);

namespace CatzPrint\Internal;

use CatzPrint\Client;
use CatzPrint\Exception\AuthenticationException;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ConnectionException;

/**
 * JSON over HTTPS with curl, retries and error mapping.
 *
 * Retries (up to RetryPolicy::$maxRetries) happen on network errors and on
 * 429/502/503/504 — but a POST is only retried when its body carries an
 * idempotency_key, so a retry can never print twice.
 *
 * @internal
 */
final class HttpClient
{
    /** Largest response body read (64 MiB); a bigger answer is an error. */
    public const MAX_RESPONSE_BYTES = 64 * 1024 * 1024;

    /** @var callable(float): void */
    private $sleeper;

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly RetryPolicy $retry,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /**
     * A copy with another timeout and retry policy (same key, base URL and
     * sleeper), e.g. for the single, short cloud attempt of cloud_first.
     */
    public function with(float $timeout, RetryPolicy $retry): self
    {
        return new self($this->apiKey, $this->baseUrl, $timeout, $retry, $this->sleeper);
    }

    /** False for a client without an API key (a paired device in local mode). */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function sleep(float $seconds): void
    {
        ($this->sleeper)($seconds);
    }

    /**
     * Sends a request and returns the decoded JSON body ([] for 204).
     *
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, bool $authenticated = true): array
    {
        return $this->requestWith($method, $path, $query, $body, null, $authenticated);
    }

    /**
     * Encodes a JSON body once, so the exact same bytes can be sent again
     * (sendEncoded()) for a retry or a re-send.
     *
     * @param array<string, mixed> $body
     */
    public static function encode(array $body): string
    {
        if ($body === []) {
            return '{}';
        }
        try {
            return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Request body could not be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Sends a pre-encoded JSON body (from encode()) unchanged. Retried like
     * request() when $retryable (it carries an idempotency key).
     *
     * @return array<string, mixed>
     */
    public function sendEncoded(string $method, string $path, string $payload, bool $retryable, ?callable $onAmbiguousRetry = null): array
    {
        return $this->requestWith($method, $path, [], null, $payload, true, $retryable, $onAmbiguousRetry);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body
     *
     * @return array<string, mixed>
     */
    private function requestWith(string $method, string $path, array $query, ?array $body, ?string $encoded, bool $authenticated, ?bool $retryableOverride = null, ?callable $onAmbiguousRetry = null): array
    {
        if ($this->apiKey === '') {
            throw new AuthenticationException(
                'This client has no API key (a paired device in local mode), so it can\'t call the CatzPrint API.',
                0,
                'missing_api_key',
            );
        }
        $method = strtoupper($method);
        $url = $this->baseUrl . $path;
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        if ($query !== []) {
            $url .= '?' . http_build_query(array_map(
                static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v,
                $query,
            ), '', '&', PHP_QUERY_RFC3986);
        }
        $payload = $encoded ?? ($body === null ? null : self::encode($body));
        $retryable = $retryableOverride ?? (($method !== 'POST' && $method !== 'PATCH')
            || (is_array($body) && isset($body['idempotency_key']) && $body['idempotency_key'] !== ''));

        for ($attempt = 0; ; $attempt++) {
            try {
                $res = $this->send($method, $url, $payload, $authenticated);
            } catch (ConnectionException $e) {
                $wait = $retryable ? $this->retry->delay($attempt) : null;
                if ($wait === null) {
                    throw $e;
                }
                if ($onAmbiguousRetry !== null && $e->requestDelivered()) {
                    $onAmbiguousRetry(); // the failed attempt may have reached the API
                }
                $this->sleep($wait);
                continue;
            }

            if ($res->status >= 200 && $res->status < 300) {
                if ($res->status === 204 || trim($res->body) === '') {
                    return [];
                }
                $decoded = json_decode($res->body, true);
                if (!is_array($decoded)) {
                    throw new CatzPrintException('CatzPrint API returned a response that is not JSON.', $res->status, 'invalid_response');
                }

                return $decoded;
            }

            $error = ErrorFactory::fromResponse($res);
            if ($retryable && RetryPolicy::isRetryableStatus($res->status)) {
                $wait = $this->retry->delay($attempt, $error->retryAfter !== null ? (float) $error->retryAfter : null);
                if ($wait !== null) {
                    $this->sleep($wait);
                    continue;
                }
            }
            throw $error;
        }
    }

    private function send(string $method, string $url, ?string $payload, bool $authenticated): Response
    {
        $headers = [
            'Accept: application/json',
            'User-Agent: ' . Client::USER_AGENT,
        ];
        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new ConnectionException('Could not initialise curl.', 0, 'connection_error');
        }
        $responseHeaders = [];
        $body = '';
        $tooLarge = false;
        $timeoutMs = max(1, (int) round($this->timeout * 1000));
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;

                    return 0; // aborts the transfer
                }
                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 10_000),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $ok = curl_exec($ch);
        if ($tooLarge) {
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            throw new CatzPrintException(
                sprintf('The CatzPrint API response is larger than %d bytes.', self::MAX_RESPONSE_BYTES),
                $status,
                'response_too_large',
            );
        }
        if ($ok === false) {
            $errno = curl_errno($ch);
            $msg = curl_error($ch);
            // Was the whole request (headers and body) sent before it failed?
            // Then the API may have acted on it ("delivered, no answer").
            $delivered = (int) curl_getinfo($ch, CURLINFO_REQUEST_SIZE) > 0
                && (int) curl_getinfo($ch, CURLINFO_SIZE_UPLOAD_T) >= strlen($payload ?? '');
            curl_close($ch);
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            $e = new ConnectionException(
                ($timedOut ? 'Request to CatzPrint timed out' : 'Could not reach CatzPrint') . ': ' . $msg,
                0,
                $timedOut ? 'timeout' : 'connection_error',
            );
            throw $delivered ? $e->markDelivered() : $e;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response($status, $responseHeaders, $body);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => Client::redact($this->apiKey), 'timeout' => $this->timeout];
    }
}

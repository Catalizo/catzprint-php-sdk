<?php

declare(strict_types=1);

namespace CatzPrint\Internal\Local;

use CatzPrint\Client;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ConnectionException;
use CatzPrint\Exception\PinMismatchException;
use CatzPrint\Internal\Response;

/**
 * HTTP/1.1 over TLS to a station, on PHP streams so the certificate can be
 * checked before a single request byte is written:
 *
 *  - IP / self-signed URLs: the chain isn't checked against CAs; instead the
 *    SHA-256 of the leaf certificate's DER must equal the pinned fingerprint
 *    (constant-time compare). No fingerprint: never contacted.
 *  - Public hostnames: normal verification (CA chain + hostname, peer_name =
 *    the hostname) PLUS the SHA-256 of the certificate's SubjectPublicKeyInfo
 *    must equal the public_spki_sha256 from the cloud endpoint list. For
 *    "<a-b-c-d>.<label>.<zone>" of a known station the TCP connection goes to
 *    the IP in the name (works while public DNS is down); the certificate is
 *    still verified for the hostname.
 *
 * Never uses a proxy, never follows redirects, one request per connection.
 *
 * @internal
 */
final class StationTransport
{
    /** Largest station answer read (4 MiB). */
    public const MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

    /** @internal Test hook for the request deadline clock (unix seconds). */
    public static ?\Closure $clockForTests = null;

    private const NO_PIN = 'No pinned certificate fingerprint for this address (IP and self-signed stations are only trusted with the fingerprint from the cloud endpoint list or the "fingerprints" option; never on first use).';

    public function __construct(
        private readonly float $connectTimeout,
        private readonly float $requestTimeout,
        private readonly ?string $caFile = null,
    ) {
    }

    /**
     * Sends one request and returns the raw response.
     *
     * Errors before the whole request was written (nothing reached the
     * station, or only part of it) are ConnectionException; errors after it
     * was written are NoAnswerException (the station may have acted on it).
     *
     * @param float|null $budget caps the whole call (connect included), in seconds
     *
     * @throws ConnectionException  connect/TLS/timeout failure, no pin (code "no_pinned_fingerprint"); not delivered
     * @throws PinMismatchException the certificate isn't the pinned one (nothing was sent)
     * @throws NoAnswerException    delivered, but no usable answer
     */
    public function request(StationTarget $t, string $method, string $pathAndQuery, #[\SensitiveParameter] ?string $token, ?string $payload, float $extraTimeout = 0.0, ?float $budget = null): Response
    {
        $connectTimeout = $budget === null ? $this->connectTimeout : max(0.05, min($this->connectTimeout, $budget));
        $deadline = microtime(true) + ($budget ?? $this->requestTimeout + $extraTimeout + $this->connectTimeout);
        $pinned = $t->needsPin();
        if ($pinned && $t->fingerprint === '') {
            throw new ConnectionException(self::NO_PIN, 0, 'no_pinned_fingerprint');
        }
        [$host, $port] = Net::urlHost($t->url);
        if ($host === '') {
            throw new ConnectionException('Invalid station URL.', 0, 'connection_error');
        }
        $port = $port === '' ? '443' : $port;
        $isIp = Net::isIp($host);
        $dialHost = $host;
        if (!$isIp && $t->label !== '' && ($ip = Net::dashedIp($host, $t->label)) !== null) {
            $dialHost = $ip;
        }

        $ssl = [
            'capture_peer_cert' => true,
            'disable_compression' => true,
            'SNI_enabled' => !$isIp,
        ];
        if (!$isIp) {
            $ssl['peer_name'] = $host;
        }
        if ($pinned) {
            // Trust comes from the fingerprint check below, not from CAs.
            $ssl += ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
        } else {
            $ssl += ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];
            if ($this->caFile !== null) {
                $ssl['cafile'] = $this->caFile;
            }
        }
        $ctx = stream_context_create(['ssl' => $ssl]);

        $addr = 'tcp://' . (str_contains($dialHost, ':') ? '[' . $dialHost . ']' : $dialHost) . ':' . $port;
        $errno = 0;
        $errstr = '';
        $timeout = $connectTimeout;
        [$sock, $warn] = self::quiet(static function () use ($addr, &$errno, &$errstr, $timeout, $ctx) {
            return stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        });
        if (!is_resource($sock)) {
            $why = $errstr !== '' ? $errstr : ($warn ?? 'connection failed');
            $timedOut = in_array($errno, [110, 60, 10060], true) || stripos($why, 'timed out') !== false;

            throw new ConnectionException('Could not connect to the station: ' . $why, 0, $timedOut ? 'timeout' : 'connection_error');
        }

        try {
            // The handshake is bounded by the connect timeout.
            stream_set_timeout($sock, (int) $connectTimeout, (int) (fmod($connectTimeout, 1.0) * 1_000_000));
            $started = microtime(true);
            [$ok, $warn] = self::quiet(static fn () => stream_socket_enable_crypto(
                $sock,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0),
            ));
            if ($ok !== true) {
                $timedOut = microtime(true) - $started >= $connectTimeout - 0.05;
                $why = $warn !== null ? preg_replace('/^stream_socket_enable_crypto\(\): /', '', $warn) : 'handshake failed';

                throw new ConnectionException(
                    ($timedOut ? 'TLS handshake with the station timed out' : 'TLS handshake with the station failed') . ': ' . $why,
                    0,
                    $timedOut ? 'timeout' : 'tls_error',
                );
            }

            // Check the pin BEFORE writing anything.
            $this->checkPin($sock, $t, $pinned);

            $requestDeadline = microtime(true) + $this->requestTimeout + $extraTimeout;
            if ($budget !== null) {
                $requestDeadline = min($requestDeadline, $deadline);
            }

            return $this->exchange($sock, $host, $port, $isIp, $method, $pathAndQuery, $token, $payload, $requestDeadline);
        } finally {
            if (is_resource($sock)) {
                self::quiet(static fn () => fclose($sock));
            }
        }
    }

    /** @param resource $sock */
    private function checkPin($sock, StationTarget $t, bool $pinned): void
    {
        $opts = stream_context_get_options($sock);
        $cert = $opts['ssl']['peer_certificate'] ?? null;
        if ($cert === null || $cert === false) {
            throw new ConnectionException('The station sent no certificate.', 0, 'tls_error');
        }
        if ($pinned) {
            $got = openssl_x509_fingerprint($cert, 'sha256');
            if (!is_string($got) || !hash_equals($t->fingerprint, strtolower($got))) {
                throw new PinMismatchException($t->fingerprint, is_string($got) ? strtolower($got) : '');
            }

            return;
        }
        if ($t->spki === '') {
            return;
        }
        $got = self::spkiSha256($cert);
        if ($got === null || !hash_equals($t->spki, $got)) {
            throw new PinMismatchException($t->spki, $got ?? '', true);
        }
    }

    /**
     * Hex SHA-256 of a certificate's SubjectPublicKeyInfo DER.
     *
     * @param \OpenSSLCertificate|string $cert
     */
    public static function spkiSha256($cert): ?string
    {
        $key = @openssl_pkey_get_public($cert);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);
        $pem = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);

        return $der === false || $der === '' ? null : hash('sha256', $der);
    }

    /** @param resource $sock */
    private function exchange($sock, string $host, string $port, bool $isIp, string $method, string $pathAndQuery, #[\SensitiveParameter] ?string $token, ?string $payload, float $deadline): Response
    {
        if ($token !== null && preg_match('/[^\x21-\x7e]/', $token) === 1) {
            throw new \InvalidArgumentException('The local token contains characters that can\'t go in an HTTP header.');
        }
        $hostHeader = ($isIp && str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $lines = [
            $method . ' ' . $pathAndQuery . ' HTTP/1.1',
            'Host: ' . $hostHeader,
            'User-Agent: ' . Client::USER_AGENT,
            'Accept: application/json',
            'Connection: close',
        ];
        if ($token !== null && $token !== '') {
            $lines[] = 'Authorization: Bearer ' . $token;
        }
        if ($payload !== null) {
            $lines[] = 'Content-Type: application/json';
            $lines[] = 'Content-Length: ' . strlen($payload);
        } elseif ($method === 'POST') {
            $lines[] = 'Content-Length: 0';
        }
        $raw = implode("\r\n", $lines) . "\r\n\r\n" . ($payload ?? '');

        $this->setTimeout($sock, $deadline);

        for ($off = 0, $len = strlen($raw); $off < $len;) {
            [$n] = self::quiet(static fn () => fwrite($sock, substr($raw, $off, 65536)));
            if (!is_int($n) || $n <= 0) {
                $this->failRead($sock, 'Could not send the request to the station');
            }
            $off += $n;
            if ($off < $len) {
                // Not after the final chunk: once the last write returned,
                // the request is delivered (a timeout now is "no answer").
                $this->setTimeout($sock, $deadline);
            }
        }

        // Everything written: from here on a failure means "delivered, no answer".
        try {
            return $this->readResponse($sock, $method, $deadline);
        } catch (\Throwable $e) {
            throw NoAnswerException::wrap($e);
        }
    }

    /** @param resource $sock */
    private function readResponse($sock, string $method, float $deadline): Response
    {
        // Status line and headers.
        $status = 0;
        $headers = [];
        $headerBytes = 0;
        $first = true;
        while (true) {
            $line = $this->readLine($sock, $deadline);
            $headerBytes += strlen($line);
            if ($headerBytes > 64 * 1024) {
                throw new CatzPrintException('The station sent oversized headers.', 0, 'invalid_response');
            }
            $line = rtrim($line, "\r\n");
            if ($first) {
                if (preg_match('#^HTTP/1\.[01] (\d{3})#', $line, $m) !== 1) {
                    throw new ConnectionException('The station did not answer with HTTP.', 0, 'connection_error');
                }
                $status = (int) $m[1];
                $first = false;
                continue;
            }
            if ($line === '') {
                if ($status >= 100 && $status < 200) {
                    $first = true; // skip interim responses
                    $headers = [];
                    continue;
                }
                break;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        // Body.
        $body = '';
        $te = strtolower($headers['transfer-encoding'] ?? '');
        if ($method === 'HEAD' || $status === 204 || $status === 304) {
            $body = '';
        } elseif (str_contains($te, 'chunked')) {
            while (true) {
                $hex = trim((string) preg_replace('/;.*$/s', '', $this->readLine($sock, $deadline)));
                $size = ctype_xdigit($hex) && strlen($hex) <= 8 ? (int) hexdec($hex) : -1;
                if ($size < 0) {
                    throw new ConnectionException('The station sent a malformed chunked body.', 0, 'connection_error');
                }
                if ($size === 0) {
                    break;
                }
                $this->guardSize($status, strlen($body) + $size);
                $body .= $this->readExact($sock, $size, $deadline);
                $this->readLine($sock, $deadline);
            }
        } elseif (isset($headers['content-length']) && ctype_digit($headers['content-length'])) {
            $n = (int) $headers['content-length'];
            $this->guardSize($status, $n);
            $body = $this->readExact($sock, $n, $deadline);
        } else {
            while (!self::quiet(static fn () => feof($sock))[0]) {
                $this->setTimeout($sock, $deadline);
                [$chunk] = self::quiet(static fn () => fread($sock, 65536));
                if ($chunk === false || ($chunk === '' && stream_get_meta_data($sock)['timed_out'])) {
                    $this->failRead($sock, 'Reading the station\'s answer failed');
                }
                $body .= $chunk;
                $this->guardSize($status, strlen($body));
            }
        }

        return new Response($status, $headers, $body);
    }

    private function guardSize(int $status, int $n): void
    {
        if ($n > self::MAX_RESPONSE_BYTES) {
            throw new CatzPrintException('The station\'s answer is too large.', $status, 'response_too_large');
        }
    }

    /** @param resource $sock */
    private function readLine($sock, float $deadline): string
    {
        $this->setTimeout($sock, $deadline);
        [$line] = self::quiet(static fn () => fgets($sock, 8192));
        if ($line === false) {
            $this->failRead($sock, 'The station closed the connection');
        }

        return $line;
    }

    /** @param resource $sock */
    private function readExact($sock, int $n, float $deadline): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $this->setTimeout($sock, $deadline);
            $want = min(65536, $n - strlen($out));
            [$chunk] = self::quiet(static fn () => fread($sock, $want));
            if ($chunk === false || $chunk === '') {
                $this->failRead($sock, 'The station closed the connection');
            }
            $out .= $chunk;
        }

        return $out;
    }

    /** @param resource $sock */
    private function setTimeout($sock, float $deadline): void
    {
        $now = self::$clockForTests !== null ? (float) (self::$clockForTests)() : microtime(true);
        $left = $deadline - $now;
        if ($left <= 0) {
            throw new ConnectionException('The request to the station timed out.', 0, 'timeout');
        }
        self::quiet(static fn () => stream_set_timeout($sock, (int) $left, (int) (fmod($left, 1.0) * 1_000_000)));
    }

    /** @param resource $sock */
    private function failRead($sock, string $what): never
    {
        $meta = @stream_get_meta_data($sock);
        if (!empty($meta['timed_out'])) {
            throw new ConnectionException('The request to the station timed out.', 0, 'timeout');
        }

        throw new ConnectionException($what . '.', 0, 'connection_error');
    }

    /**
     * Runs $fn with warnings captured instead of printed.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return array{0: T, 1: string|null}
     */
    private static function quiet(callable $fn): array
    {
        $warning = null;
        set_error_handler(static function (int $no, string $msg) use (&$warning): bool {
            $warning = $msg;

            return true;
        });
        try {
            return [$fn(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}

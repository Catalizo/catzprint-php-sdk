<?php

declare(strict_types=1);

namespace CatzPrint;

use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Internal\HttpClient;
use CatzPrint\Internal\RetryPolicy;
use CatzPrint\Model\Job;
use CatzPrint\Model\Key;
use CatzPrint\Resource\Jobs;
use CatzPrint\Resource\Locations;
use CatzPrint\Resource\Printers;
use CatzPrint\Resource\Templates;
use CatzPrint\Resource\Webhooks;

/**
 * The CatzPrint API client.
 *
 *     $catz = new CatzPrint\Client('cz_test_…');          // or CATZPRINT_API_KEY
 *     $job  = $catz->printText("Hello\n", ['role' => 'receipt']);
 *     $job  = $catz->jobs->waitFor($job->id);
 */
final class Client
{
    public const VERSION = '2.1.3';
    public const USER_AGENT = 'catzprint-php/' . self::VERSION;
    public const DEFAULT_BASE_URL = 'https://api-v1.catzprint.com';

    public readonly Jobs $jobs;
    public readonly Printers $printers;
    public readonly Locations $locations;
    public readonly Templates $templates;
    public readonly Webhooks $webhooks;

    private readonly HttpClient $http;
    private readonly string $apiKey;
    private ?string $orgId;
    private ?Key $key = null;
    private ?string $serverKey = null;
    private float $serverKeyFetchedAt = 0.0;

    /** Seconds the server public key is cached for. */
    private const SERVER_KEY_TTL = 300.0;

    /**
     * @param string|null $apiKey  cz_live_… or cz_test_…; defaults to env CATZPRINT_API_KEY.
     * @param array{
     *   baseUrl?: string, orgId?: string, timeout?: float, maxRetries?: int,
     *   sleeper?: callable(float): void, random?: callable(): float
     * } $options
     *   - baseUrl:    API root (default env CATZPRINT_BASE_URL or https://api-v1.catzprint.com)
     *   - orgId:      skip the GET /v1/key lookup
     *   - timeout:    seconds per request (default 30)
     *   - maxRetries: retries on network errors, 429 and 502/503/504 (default 2)
     *   - sleeper / random: test hooks for backoff
     */
    public function __construct(#[\SensitiveParameter] ?string $apiKey = null, array $options = [])
    {
        $unknown = array_diff(array_keys($options), ['baseUrl', 'orgId', 'timeout', 'maxRetries', 'sleeper', 'random']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown client option(s): ' . implode(', ', $unknown) . '.');
        }
        $apiKey ??= self::env('CATZPRINT_API_KEY');
        if ($apiKey === null || trim($apiKey) === '') {
            throw new \InvalidArgumentException('No CatzPrint API key: pass one to new CatzPrint\Client() or set CATZPRINT_API_KEY.');
        }
        $this->apiKey = trim($apiKey);
        $baseUrl = $options['baseUrl'] ?? self::env('CATZPRINT_BASE_URL') ?? self::DEFAULT_BASE_URL;
        $this->orgId = $options['orgId'] ?? null;

        $retry = new RetryPolicy(
            maxRetries: max(0, (int) ($options['maxRetries'] ?? 2)),
            random: $options['random'] ?? null,
        );
        $this->http = new HttpClient(
            $this->apiKey,
            self::checkBaseUrl((string) $baseUrl),
            (float) ($options['timeout'] ?? 30.0),
            $retry,
            $options['sleeper'] ?? null,
        );

        $this->jobs = new Jobs($this);
        $this->printers = new Printers($this);
        $this->locations = new Locations($this);
        $this->templates = new Templates($this);
        $this->webhooks = new Webhooks();
    }

    /** The API key in use and its organization (GET /v1/key, cached). */
    public function key(bool $refresh = false): Key
    {
        if ($this->key === null || $refresh) {
            $this->key = new Key($this->http->request('GET', '/v1/key'));
            $this->orgId ??= $this->key->orgId;
        }

        return $this->key;
    }

    /** Alias of key(). */
    public function whoami(): Key
    {
        return $this->key();
    }

    /** True for cz_live_ keys (judged from the prefix; no request). */
    public function isLive(): bool
    {
        return str_starts_with($this->apiKey, 'cz_live_');
    }

    /** The organization ID: the orgId option, or looked up once with key(). */
    public function orgId(): string
    {
        if ($this->orgId === null) {
            $this->key();
        }
        if ($this->orgId === null || $this->orgId === '') {
            throw new CatzPrintException('Could not determine the organization for this API key.', 0, 'no_org');
        }

        return $this->orgId;
    }

    /**
     * The server's X25519 public key (base64) for sealed content, cached for
     * five minutes. GET /v1/public/server-key.
     */
    public function serverKey(bool $refresh = false): string
    {
        if ($refresh || $this->serverKey === null || microtime(true) - $this->serverKeyFetchedAt > self::SERVER_KEY_TTL) {
            $res = $this->http->request('GET', '/v1/public/server-key', [], null, false);
            $pk = $res['public_key'] ?? null;
            if (!is_string($pk) || $pk === '') {
                throw new CatzPrintException('The server did not return a public key.', 0, 'invalid_response');
            }
            $this->serverKey = $pk;
            $this->serverKeyFetchedAt = microtime(true);
        }

        return $this->serverKey;
    }

    // ---- convenience helpers ---------------------------------------------

    /**
     * Prints plain text.
     *
     * @param array<string, mixed> $opts Any Jobs::create() params (printerId, role, title, sealed…).
     */
    public function printText(string $text, array $opts = []): Job
    {
        return $this->jobs->create(['text' => $text] + $opts);
    }

    /**
     * Prints a PDF given as bytes or a file path.
     *
     * @param array<string, mixed> $opts
     */
    public function printPdf(string $pdfOrPath, array $opts = []): Job
    {
        [$bytes, $name] = self::bytesOrFile($pdfOrPath, '%PDF-');
        if ($name !== null) {
            $opts['title'] ??= $name;
        }

        return $this->jobs->create(['kind' => 'pdf', 'content' => $bytes] + $opts);
    }

    /**
     * Prints a PNG or JPEG given as bytes or a file path.
     *
     * @param array<string, mixed> $opts
     */
    public function printImage(string $imageOrPath, array $opts = []): Job
    {
        [$bytes, $name] = self::bytesOrFile($imageOrPath, null);
        if ($name !== null) {
            $opts['title'] ??= $name;
        }

        return $this->jobs->create(['kind' => 'image', 'content' => $bytes] + $opts);
    }

    /**
     * Prints a receipt from commands (see the commands docs).
     *
     * @param list<array<string, mixed>> $commands
     * @param array<string, mixed>       $opts
     */
    public function printReceipt(array $commands, array $opts = []): Job
    {
        return $this->jobs->create(['commands' => $commands] + $opts);
    }

    /**
     * Prints a saved template filled with $data.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $opts
     */
    public function printTemplate(string $templateId, array $data = [], array $opts = []): Job
    {
        return $this->jobs->create(['templateId' => $templateId, 'data' => $data] + $opts);
    }

    // ---- internals -------------------------------------------------------

    /** @internal */
    public function http(): HttpClient
    {
        return $this->http;
    }

    /** @internal "/v1/orgs/{org}" + $suffix */
    public function orgPath(string $suffix): string
    {
        return '/v1/orgs/' . rawurlencode($this->orgId()) . $suffix;
    }

    /** Masks a secret for logs: "cz_live_…a1b2". */
    public static function redact(string $secret): string
    {
        if (preg_match('/^(cz_(?:live|test)_)/', $secret, $m)) {
            return $m[1] . '…' . (strlen($secret) > 16 ? substr($secret, -4) : '');
        }

        return '…';
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => self::redact($this->apiKey),
            'baseUrl' => $this->http->baseUrl(),
            'orgId' => $this->orgId,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('CatzPrint\Client holds an API key and cannot be serialized.');
    }

    /**
     * @return array{0: string, 1: string|null} bytes and, when read from disk, the file name
     */
    private static function bytesOrFile(string $value, ?string $magic): array
    {
        $looksLikeBytes = ($magic !== null && str_starts_with($value, $magic))
            || str_starts_with($value, '%PDF-')
            || str_starts_with($value, "\x89PNG")
            || str_starts_with($value, "\xff\xd8\xff")
            || str_contains($value, "\0")
            || strlen($value) > 4096;
        // Only local paths are read: never stream wrappers (phar://,
        // php://, data://, http://…), which could fetch or unserialize data.
        // A file:// URL is a local path, so it is turned into one and read
        // directly (never through a wrapper), with the same type check.
        $path = $value;
        if (preg_match('#^file://(?:localhost)?(/.*)$#is', $value, $m) === 1) {
            $path = $m[1];
            if (preg_match('#^/[a-zA-Z]:[/\\\\]#', $path) === 1) {
                $path = substr($path, 1); // file:///C:/x on Windows
            }
        }
        $isWrapper = preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path) === 1;
        if (!$looksLikeBytes && !$isWrapper && is_file($path)) {
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \InvalidArgumentException('Could not read the file.');
            }
            // A string that is both "content" and an existing path is
            // ambiguous; only accept the file when it really is the expected
            // type, so untrusted content that happens to name a local file
            // (e.g. "/etc/passwd") can't make the SDK print that file.
            $ok = $magic === '%PDF-'
                ? str_contains(substr($bytes, 0, 1024), '%PDF-')
                : (str_starts_with($bytes, "\x89PNG") || str_starts_with($bytes, "\xff\xd8\xff"));
            if (!$ok) {
                throw new \InvalidArgumentException(
                    $magic === '%PDF-' ? 'The file is not a PDF.' : 'The file is not a PNG or JPEG image.',
                );
            }

            return [$bytes, basename($path)];
        }

        return [$value, null];
    }

    /**
     * Validates the API base URL and returns it without a trailing slash.
     * The API key travels with every request, so it must be https; plain http
     * is accepted only for a local server (tests, development proxies).
     */
    public static function checkBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new \InvalidArgumentException('The CatzPrint base URL is not a valid URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('The CatzPrint base URL must not contain credentials.');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('The CatzPrint base URL must not contain a query or fragment.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new \InvalidArgumentException(
                'The CatzPrint base URL must use https:// (http:// is allowed only for localhost), so the API key is not sent in clear text.',
            );
        }

        return $url;
    }

    private static function env(string $name): ?string
    {
        $v = getenv($name);

        return is_string($v) && $v !== '' ? $v : null;
    }
}

<?php

declare(strict_types=1);

namespace CatzPrint\Internal;

/**
 * One cloud job create: the body is built once, and sealed and encoded once
 * (on the first attempt), so the ambiguous-outcome retry and the 409 re-sends
 * send exactly the same bytes. Only a server-key rotation (an answered 422
 * on sealed_content) re-seals, once.
 *
 * @internal
 */
final class CloudJob
{
    /** The org path, resolved on the first attempt. */
    public ?string $path = null;
    /** The exact JSON bytes sent, set on the first attempt. */
    public ?string $payload = null;
    public bool $resealed = false;
    /** An attempt may already have reached the API (sent, then no usable answer; or a 409 "duplicate"). */
    public bool $ambiguous = false;

    /**
     * @param array<string, mixed> $body   the jobs.create body without sealed_content
     * @param string|null          $bytes  what to seal (when $sealed)
     */
    public function __construct(
        public readonly array $body,
        public readonly ?string $bytes,
        public readonly bool $sealed,
    ) {
    }

    public function idempotencyKey(): string
    {
        return (string) ($this->body['idempotency_key'] ?? '');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['path' => $this->path, 'idempotencyKey' => $this->idempotencyKey(), 'sealed' => $this->sealed];
    }
}

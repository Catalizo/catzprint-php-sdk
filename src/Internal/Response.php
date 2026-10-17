<?php

declare(strict_types=1);

namespace CatzPrint\Internal;

/**
 * A raw HTTP response.
 *
 * @internal
 */
final class Response
{
    /**
     * @param array<string, string> $headers Lower-cased header names.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

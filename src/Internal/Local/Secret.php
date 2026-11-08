<?php

declare(strict_types=1);

namespace CatzPrint\Internal\Local;

/**
 * Holds a token where a plain string would show up in debug output (values
 * captured by closures appear in print_r/var_dump of exception traces).
 *
 * @internal
 */
final class Secret
{
    public function __construct(#[\SensitiveParameter] private readonly string $value)
    {
    }

    public function get(): string
    {
        return $this->value;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A secret cannot be serialized.');
    }
}

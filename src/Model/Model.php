<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/**
 * Base for API objects. Known fields are typed public readonly properties;
 * every field the API sent (including ones this SDK version doesn't know
 * yet) stays reachable through array access, toArray() and magic reads.
 *
 * @implements \ArrayAccess<string, mixed>
 */
abstract class Model implements \ArrayAccess, \JsonSerializable
{
    /** @var array<string, mixed> */
    protected array $raw;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->raw = $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    /** @return array<string, mixed> The object exactly as the API sent it. */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }

    /** Reads a field this SDK version has no property for. */
    public function __get(string $name): mixed
    {
        return $this->raw[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->raw[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->raw[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->raw[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException(static::class . ' is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException(static::class . ' is read-only.');
    }

    // ---- helpers for subclasses ------------------------------------------

    /** @param array<string, mixed> $d */
    protected static function str(array $d, string $k): string
    {
        $v = $d[$k] ?? '';

        return is_scalar($v) ? (string) $v : '';
    }

    /** @param array<string, mixed> $d */
    protected static function strOrNull(array $d, string $k): ?string
    {
        $v = $d[$k] ?? null;

        return is_scalar($v) ? (string) $v : null;
    }

    /** @param array<string, mixed> $d */
    protected static function int(array $d, string $k): int
    {
        $v = $d[$k] ?? 0;

        return is_numeric($v) ? (int) $v : 0;
    }

    /** @param array<string, mixed> $d */
    protected static function bool(array $d, string $k): bool
    {
        return (bool) ($d[$k] ?? false);
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return list<string>
     */
    protected static function strList(array $d, string $k): array
    {
        $v = $d[$k] ?? [];

        return is_array($v) ? array_values(array_map('strval', array_filter($v, 'is_scalar'))) : [];
    }

    /** @param array<string, mixed> $d */
    protected static function date(array $d, string $k): ?\DateTimeImmutable
    {
        $v = $d[$k] ?? null;
        if (!is_string($v) || $v === '') {
            return null;
        }
        // Go may send up to nanoseconds; PHP keeps microseconds.
        $v = (string) preg_replace('/(\.\d{6})\d+/', '$1', $v);
        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception) {
            return null;
        }
    }
}

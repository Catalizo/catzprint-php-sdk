<?php

declare(strict_types=1);

namespace CatzPrint\Model;

/** A saved list of receipt commands with {{placeholders}}. */
final class Template extends Model
{
    public readonly string $id;
    public readonly string $name;
    /** 80mm or 58mm. */
    public readonly string $paper;
    /** @var array<int|string, mixed> The command list (see the commands docs). */
    public readonly array $body;
    public readonly int $version;
    public readonly ?\DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id = self::str($data, 'id');
        $this->name = self::str($data, 'name');
        $this->paper = self::str($data, 'paper');
        $this->body = is_array($data['body'] ?? null) ? $data['body'] : [];
        $this->version = self::int($data, 'version');
        $this->updatedAt = self::date($data, 'updated_at');
    }
}

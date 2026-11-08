<?php

declare(strict_types=1);

namespace CatzPrint\Local;

/**
 * Keeps tokens in memory (the default). In PHP that means for one request or
 * worker only, so a token is minted again on the next request: use
 * FileTokenStore (or your own TokenStore) in production.
 */
final class MemoryTokenStore implements AtomicTokenStore
{
    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['keys' => array_keys($this->items)];
    }

    public function load(string $key): ?StoredToken
    {
        return isset($this->items[$key]) ? StoredToken::fromArray($this->items[$key]) : null;
    }

    public function save(string $key, #[\SensitiveParameter] ?StoredToken $token): void
    {
        if ($token === null) {
            unset($this->items[$key]);

            return;
        }
        $this->items[$key] = $token->toArray();
    }

    public function update(string $key, callable $fn): ?StoredToken
    {
        $new = $fn($this->load($key));
        if ($new !== null) {
            $this->save($key, $new);
        }

        return $this->load($key);
    }
}

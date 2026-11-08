<?php

declare(strict_types=1);

namespace CatzPrint\Local;

/**
 * Persists local tokens and the cloud endpoint list between runs (PHP
 * requests). load() returns null when nothing is stored under $key; save()
 * with null deletes the key. Keys are opaque strings chosen by the SDK; they
 * never contain the API key itself.
 *
 * Implement AtomicTokenStore too when several PHP processes share the store
 * (FileTokenStore does), so the "re-mint at most once per 10 minutes" rule
 * holds across processes.
 */
interface TokenStore
{
    public function load(string $key): ?StoredToken;

    public function save(string $key, #[\SensitiveParameter] ?StoredToken $token): void;
}

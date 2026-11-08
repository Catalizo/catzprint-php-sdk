<?php

declare(strict_types=1);

namespace CatzPrint\Local;

/** A TokenStore that can read-modify-write one key atomically. */
interface AtomicTokenStore extends TokenStore
{
    /**
     * Calls $fn with the stored value (or null) while holding the store's
     * lock; when $fn returns a StoredToken it is saved. Returns what is
     * stored afterwards.
     *
     * @param callable(?StoredToken): ?StoredToken $fn Return null to leave the value unchanged.
     */
    public function update(string $key, callable $fn): ?StoredToken;
}

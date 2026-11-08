<?php

declare(strict_types=1);

namespace CatzPrint\Local;

/**
 * Keeps tokens in a JSON file readable only by the current user (mode 0600;
 * a missing directory is created with 0700). Every save writes a temporary
 * file next to it, fsyncs it and renames it into place, so the file is never
 * half-written; readers and writers across PHP processes are serialized with
 * flock() on "<path>.lock". One file can hold several keys.
 *
 * Put the file outside the web root, e.g. /var/lib/catzserve/catzprint-local.json.
 *
 * On POSIX systems an existing store file, lock file or directory that isn't
 * owned by the current user, or that is group- or world-writable, is refused
 * (RuntimeException) instead of being used: someone else could plant or read
 * tokens there. Windows skips this check.
 */
final class FileTokenStore implements AtomicTokenStore
{
    public function __construct(private readonly string $path)
    {
        if ($path === '' || preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path) === 1) {
            throw new \InvalidArgumentException('FileTokenStore needs a local file path (no stream wrappers).');
        }
    }

    /** The file the store uses. */
    public function path(): string
    {
        return $this->path;
    }

    public function load(string $key): ?StoredToken
    {
        return $this->locked(LOCK_SH, function () use ($key): ?StoredToken {
            $all = $this->readAll(true);
            $v = $all[$key] ?? null;

            return is_array($v) ? StoredToken::fromArray($v) : null;
        });
    }

    public function save(string $key, #[\SensitiveParameter] ?StoredToken $token): void
    {
        $this->locked(LOCK_EX, function () use ($key, $token): void {
            $this->write($key, $token);
        });
    }

    public function update(string $key, callable $fn): ?StoredToken
    {
        return $this->locked(LOCK_EX, function () use ($key, $fn): ?StoredToken {
            $all = $this->readAll(false);
            $cur = is_array($all[$key] ?? null) ? StoredToken::fromArray($all[$key]) : null;
            $new = $fn($cur);
            if ($new === null) {
                return $cur;
            }
            $this->write($key, $new);

            return $new;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function locked(int $mode, callable $fn): mixed
    {
        $this->ensureDir();
        $lockPath = $this->path . '.lock';
        $this->checkSafe(dirname($this->path));
        $this->checkSafe($lockPath);
        $this->checkSafe($this->path);
        $old = umask(0o077);
        try {
            $lock = @fopen($lockPath, 'c');
        } finally {
            umask($old);
        }
        if ($lock === false) {
            throw new \RuntimeException('Could not open the token store lock file ' . $lockPath . '.');
        }
        try {
            if (!flock($lock, $mode)) {
                throw new \RuntimeException('Could not lock the token store ' . $this->path . '.');
            }

            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    private function readAll(bool $strict): array
    {
        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException('Could not read the token store ' . $this->path . '.');
        }
        if (trim($raw) === '') {
            return [];
        }
        $all = json_decode($raw, true);
        if (!is_array($all)) {
            if ($strict) {
                throw new \RuntimeException('The token store ' . $this->path . ' is corrupt.');
            }

            return []; // a corrupt file is replaced rather than blocking printing
        }

        return $all;
    }

    private function write(string $key, #[\SensitiveParameter] ?StoredToken $token): void
    {
        $all = $this->readAll(false);
        if ($token === null) {
            unset($all[$key]);
        } else {
            $all[$key] = $token->toArray();
        }
        $json = json_encode($all === [] ? new \stdClass() : $all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $dir = dirname($this->path);
        $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($this->path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $old = umask(0o077);
        try {
            $f = @fopen($tmp, 'x');
        } finally {
            umask($old);
        }
        if ($f === false) {
            throw new \RuntimeException('Could not write the token store ' . $this->path . '.');
        }
        $ok = false;
        try {
            @chmod($tmp, 0o600);
            $ok = fwrite($f, $json) === strlen($json) && fflush($f) && (!function_exists('fsync') || fsync($f));
            $ok = fclose($f) && $ok;
            if ($ok) {
                $ok = @rename($tmp, $this->path);
            }
        } finally {
            if (!$ok) {
                @unlink($tmp);
            }
        }
        if (!$ok) {
            throw new \RuntimeException('Could not write the token store ' . $this->path . '.');
        }
    }

    private ?int $euid = null;

    /**
     * The effective uid: posix_geteuid(), or, without ext-posix, the owner
     * of a probe file created in the store directory (files are created
     * with the effective uid). Fails closed when neither works.
     */
    private function effectiveUid(): int
    {
        if ($this->euid !== null) {
            return $this->euid;
        }
        if (function_exists('posix_geteuid')) {
            return $this->euid = posix_geteuid();
        }
        $probe = dirname($this->path) . DIRECTORY_SEPARATOR . '.catzprint-owner-' . bin2hex(random_bytes(6));
        $f = @fopen($probe, 'x');
        $uid = false;
        if ($f !== false) {
            fclose($f);
            clearstatcache(true, $probe);
            $uid = @fileowner($probe);
            @unlink($probe);
        }
        if (!is_int($uid)) {
            throw new \RuntimeException(sprintf('Could not determine the current user to check the token store %s; refusing to use it.', $this->path));
        }

        return $this->euid = $uid;
    }

    /** Refuses an existing path not owned by us or writable by group/others (POSIX only). */
    private function checkSafe(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }
        clearstatcache(true, $path);
        $st = @stat($path);
        if ($st === false) {
            return; // doesn't exist yet: created by us with private permissions
        }
        if ($st['uid'] !== $this->effectiveUid()) {
            throw new \RuntimeException(sprintf('The token store path %s is not owned by the current user; refusing to use it.', $path));
        }
        if (($st['mode'] & 0o022) !== 0) {
            throw new \RuntimeException(sprintf('The token store path %s is writable by group or others (mode %o); refusing to use it.', $path, $st['mode'] & 0o777));
        }
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the token store directory ' . $dir . '.');
        }
    }
}

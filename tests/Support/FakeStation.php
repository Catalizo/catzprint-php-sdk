<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Support;

/**
 * Runs tests/Support/fake_station.php (a TLS fake station) in the background
 * with a certificate from tests/fixtures/local and scripts it.
 */
final class FakeStation
{
    /** @var resource|null */
    private $proc;
    private string $dir;
    public readonly string $url;
    /** Lower-case hex SHA-256 of the certificate DER. */
    public readonly string $fp;
    public readonly int $port;

    /** @param string $cert "station" (self-signed, IP 127.0.0.1) or "public" (CA-signed *.abcdefghij.local.catzprint.com) */
    public function __construct(string $cert = 'station')
    {
        $fixtures = dirname(__DIR__) . '/fixtures/local';
        $certFile = "$fixtures/$cert.crt";
        $this->fp = strtolower((string) openssl_x509_fingerprint((string) file_get_contents($certFile), 'sha256'));
        $this->dir = sys_get_temp_dir() . '/czstation-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
        file_put_contents("$this->dir/config", '{}');
        $cmd = [PHP_BINARY, __DIR__ . '/fake_station.php', $this->dir, $certFile, "$fixtures/$cert.key"];
        $this->proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->dir . '/stderr', 'w']], $pipes);
        if (!is_resource($this->proc)) {
            throw new \RuntimeException('Could not start the fake station');
        }
        for ($i = 0; $i < 200 && !is_file("$this->dir/port"); $i++) {
            usleep(10_000);
        }
        if (!is_file("$this->dir/port")) {
            throw new \RuntimeException('The fake station did not start: ' . @file_get_contents("$this->dir/stderr"));
        }
        $this->port = (int) file_get_contents("$this->dir/port");
        $this->url = 'https://127.0.0.1:' . $this->port;
    }

    /** @param array<string, mixed> $config see fake_station.php */
    public function configure(array $config): void
    {
        file_put_contents("$this->dir/config.tmp", json_encode($config + json_decode((string) file_get_contents("$this->dir/config"), true)));
        rename("$this->dir/config.tmp", "$this->dir/config");
    }

    /** @return list<array<string, mixed>> */
    private function log(string $type): array
    {
        $out = [];
        foreach (explode("\n", (string) @file_get_contents("$this->dir/log")) as $line) {
            $e = json_decode($line, true);
            if (is_array($e) && $e['type'] === $type) {
                $out[] = $e;
            }
        }

        return $out;
    }

    /** @return list<array{method: string, path: string, query: string, auth: string, body: array<string, mixed>, host: string}> */
    public function requests(): array
    {
        return $this->log('req');
    }

    /** @return list<array<string, mixed>> */
    public function jobRequests(): array
    {
        return array_values(array_filter($this->requests(), static fn (array $r): bool => $r['method'] === 'POST' && $r['path'] === '/local/v1/jobs'));
    }

    /** @return list<array{bytes: int, tls: bool}> one per TCP connection */
    public function connections(): array
    {
        return $this->log('conn');
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
        foreach (glob("$this->dir/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function __destruct()
    {
        $this->stop();
    }
}

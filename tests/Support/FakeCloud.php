<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Support;

/**
 * A fake CatzPrint API (fake_cloud_router.php under php -S) for the local
 * printing tests: mints czl1 tokens "jti-N", serves the endpoint list and
 * takes jobs for org "org1".
 */
final class FakeCloud
{
    /** @var resource|null */
    private $proc;
    private string $dir;
    public readonly string $url;

    public function __construct()
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new \RuntimeException("No free port: $errstr");
        }
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $this->dir = sys_get_temp_dir() . '/czcloud-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
        file_put_contents("$this->dir/config", '{}');
        touch("$this->dir/log");
        $this->url = "http://127.0.0.1:$port";
        $env = getenv();
        $env['FAKE_CLOUD_DIR'] = $this->dir;
        $env['PHP_CLI_SERVER_WORKERS'] = '4'; // a slow answer must not block the next request
        $this->proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake_cloud_router.php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        for ($i = 0; $i < 200; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
            if ($c !== false) {
                fclose($c);

                return;
            }
            usleep(20_000);
        }
        throw new \RuntimeException('Fake cloud did not start');
    }

    /** @param array<string, mixed> $config */
    public function configure(array $config): void
    {
        $cur = json_decode((string) file_get_contents("$this->dir/config"), true);
        file_put_contents("$this->dir/config.tmp", json_encode($config + (is_array($cur) ? $cur : [])));
        rename("$this->dir/config.tmp", "$this->dir/config");
    }

    /** @return list<array{method: string, path: string, query: string, body: array<string, mixed>, auth: string}> */
    public function requests(): array
    {
        $out = [];
        foreach (explode("\n", (string) file_get_contents("$this->dir/log")) as $line) {
            $e = json_decode($line, true);
            if (is_array($e)) {
                $out[] = $e;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> bodies of POST /local-tokens */
    public function mints(): array
    {
        return $this->bodies('POST', '/v1/orgs/org1/local-tokens');
    }

    /** @return list<array<string, mixed>> bodies of POST /jobs */
    public function jobs(): array
    {
        return $this->bodies('POST', '/v1/orgs/org1/jobs');
    }

    public function endpointCalls(): int
    {
        return count($this->bodies('GET', '/v1/orgs/org1/local-endpoints'));
    }

    /** @return list<array<string, mixed>> */
    private function bodies(string $method, string $path): array
    {
        $out = [];
        foreach ($this->requests() as $r) {
            if ($r['method'] === $method && $r['path'] === $path) {
                $out[] = $r['body'];
            }
        }

        return $out;
    }

    public function reset(): void
    {
        file_put_contents("$this->dir/config", '{}');
        file_put_contents("$this->dir/log", '');
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

<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Support;

/**
 * Starts tests/Support/fake_router.php under PHP's built-in web server on a
 * free local port, and scripts its responses.
 */
final class FakeServer
{
    /** @var resource|null */
    private $proc;
    private string $stateFile;
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

        $this->stateFile = tempnam(sys_get_temp_dir(), 'czfake') ?: throw new \RuntimeException('tempnam failed');
        file_put_contents($this->stateFile, json_encode(['queue' => [], 'requests' => []]));
        $this->url = "http://127.0.0.1:$port";

        $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake_router.php'];
        $env = getenv();
        $env['FAKE_STATE'] = $this->stateFile;
        $this->proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        if (!is_resource($this->proc)) {
            throw new \RuntimeException('Could not start php -S');
        }
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
            if ($c !== false) {
                fclose($c);

                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('Fake server did not start');
    }

    /**
     * Queues responses: each is [status, json-body?, headers?, delay?].
     *
     * @param list<array{status?: int, json?: mixed, body?: string, headers?: array<string, string>, delay?: float}> $responses
     */
    public function queue(array $responses): void
    {
        $this->call('POST', '/__queue', json_encode($responses, JSON_THROW_ON_ERROR));
    }

    /** @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}> */
    public function requests(): array
    {
        return json_decode($this->call('GET', '/__requests', null), true, 512, JSON_THROW_ON_ERROR);
    }

    public function reset(): void
    {
        $this->call('POST', '/__reset', '');
    }

    private function call(string $method, string $path, ?string $body): string
    {
        $ch = curl_init($this->url . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        curl_close($ch);

        return is_string($out) ? $out : '';
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
        @unlink($this->stateFile);
    }

    public function __destruct()
    {
        $this->stop();
    }
}

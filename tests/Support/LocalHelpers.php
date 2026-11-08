<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Support;

use CatzPrint\Client;

/**
 * Shared helpers for the local printing tests: one fake cloud per class,
 * fake stations per test, and a client with fast local timeouts.
 */
trait LocalHelpers
{
    private static ?FakeCloud $cloud = null;
    /** @var list<FakeStation> */
    private array $stations = [];

    public static function setUpBeforeClass(): void
    {
        self::$cloud = new FakeCloud();
    }

    public static function tearDownAfterClass(): void
    {
        self::$cloud?->stop();
        self::$cloud = null;
    }

    protected function setUp(): void
    {
        self::cloud()->reset();
        $this->stations = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->stations as $s) {
            $s->stop();
        }
        $this->stations = [];
    }

    private static function cloud(): FakeCloud
    {
        return self::$cloud ?? throw new \LogicException('cloud not started');
    }

    private function station(string $cert = 'station'): FakeStation
    {
        $s = new FakeStation($cert);
        $this->stations[] = $s;

        return $s;
    }

    /**
     * @param array<string, mixed> $local   the "local" option (fast timeouts and deviceName "Hub" added)
     * @param string|null          $baseUrl default: the fake cloud; "down" for a closed port
     * @param array<string, mixed> $options more client options
     */
    private function localClient(array $local, ?string $baseUrl = null, array $options = [], ?string $apiKey = 'cz_test_localTESTsecretKEY0123456789'): Client
    {
        $local += ['connectTimeout' => 1.0, 'requestTimeout' => 2.0, 'cloudTimeout' => 2.0, 'discoverTimeout' => 0.1, 'deviceName' => 'Hub'];
        if (($local['locationId'] ?? '') === '' && ($local['token'] ?? '') === '') {
            $local += ['allLocations' => true];
        }
        $base = $baseUrl === 'down' ? self::deadHttp() : ($baseUrl ?? self::cloud()->url);

        return new Client($apiKey, $options + [
            'baseUrl' => $base,
            'orgId' => 'org1',
            'maxRetries' => 0,
            'sleeper' => static function (float $s): void {
            },
            'local' => $local,
        ]);
    }

    /** An https URL on a closed local port. */
    private static function deadUrl(): string
    {
        return 'https://127.0.0.1:' . self::freePort();
    }

    private static function deadHttp(): string
    {
        return 'http://127.0.0.1:' . self::freePort();
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        if ($s === false) {
            throw new \RuntimeException('no port');
        }
        $name = (string) stream_socket_get_name($s, false);
        fclose($s);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/cztest-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);

        return $dir;
    }

    private static function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$dir/$f") ? self::rmTree("$dir/$f") : @unlink("$dir/$f");
        }
        @rmdir($dir);
    }

    /** Runs $fn and returns what it threw (null if nothing). */
    private static function thrown(callable $fn): ?\Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /** @return array<string, mixed> kitchen ticket params */
    private static function kitchen(string $key): array
    {
        return [
            'role' => 'kitchen',
            'templateId' => 'tpl-kitchen',
            'data' => ['table' => '12'],
            'reference' => 'order-1042',
            'idempotencyKey' => $key,
        ];
    }

    private static function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/local/' . $name;
    }
}

<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Exception\ConflictException;
use CatzPrint\Exception\OutcomeUnknownException;
use CatzPrint\Internal\Local\NoAnswerException;
use CatzPrint\Internal\Local\StationTransport;
use CatzPrint\Local\MemoryTokenStore;
use CatzPrint\Local\Mode;
use CatzPrint\Local\StoredToken;
use CatzPrint\Model\Job;
use CatzPrint\Model\LocalPairing;
use CatzPrint\Model\LocalToken;
use CatzPrint\Tests\Support\LocalHelpers;
use CatzPrint\Tests\TestCase;

/**
 * SDK addendum 4 (audit round 6) regression tests for PHP: Q1, Q2, Q3,
 * Q7(c), Q7(d).
 */
final class Audit6Test extends TestCase
{
    use LocalHelpers;

    /** @return array<string, mixed> */
    private function pinned(string ...$urls): array
    {
        $pins = [];
        foreach ($this->stations as $s) {
            $pins[$s->url] = $s->fp;
        }

        return ['stationUrls' => $urls, 'fingerprints' => $pins];
    }

    /** @return array<string, mixed> */
    private static function quiet(): array
    {
        return ['sleeper' => static function (float $s): void {
        }];
    }

    // ---- Q1: 409 in_progress ---------------------------------------------------

    public function testProbeInProgressKeepsProbingThenOutcomeUnknown(): void
    {
        $a = $this->station();
        $a->configure(['drop' => ['POST /local/v1/jobs' => 'norecord'], 'fail' => ['GET /local/v1/jobs' => [409, 'in_progress']]]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($a->url));
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-ip']));
        $this->assertInstanceOf(OutcomeUnknownException::class, $err);
        $this->assertCount(3, array_filter($a->requests(), static fn ($r) => $r['method'] === 'GET'));
        $this->assertCount(0, self::cloud()->jobs());
    }

    public function testPostInProgressWaitsForTheJobByKey(): void
    {
        $a = $this->station();
        $b = $this->station();
        // 409 in_progress, then the probe finds the job.
        $a->configure(['drop' => ['POST /local/v1/jobs' => 'inprogress']]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($a->url, $b->url));
        $job = $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-ip2']);
        $this->assertSame($a->url, $job->local?->stationUrl);
        $this->assertSame('k-ip2', $job->idempotencyKey);

        // 409 in_progress, then the probe says duplicate: that conflict.
        $a->configure(['fail' => ['GET /local/v1/jobs' => [409, 'duplicate']]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-ip3']));
        $this->assertInstanceOf(ConflictException::class, $err);
        $this->assertSame('duplicate', $err->errorCode);

        // 409 in_progress, then the probe says 404: still never a fallback.
        $a->configure(['fail' => ['POST /local/v1/jobs' => [409, 'in_progress']], 'drop' => []]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-ip4']));
        $this->assertInstanceOf(OutcomeUnknownException::class, $err);
        $this->assertCount(0, $b->requests(), 'no other station');
        $this->assertCount(0, self::cloud()->jobs(), 'no cloud');
    }

    // ---- Q2 ----------------------------------------------------------------------

    public function testTokensSerializeButDebugOutputIsRedacted(): void
    {
        $lt = new LocalToken(['token' => 'czl1.payload.sig', 'token_id' => 'jti-1']);
        $this->assertSame('czl1.payload.sig', json_decode((string) json_encode($lt), true)['token']);
        $this->assertStringNotContainsString('payload', print_r($lt, true));
        $p = new LocalPairing(['token' => 'czd_devicetoken', 'device_id' => 'd_1']);
        $this->assertSame('czd_devicetoken', json_decode((string) json_encode($p), true)['token']);
        $this->assertStringNotContainsString('devicetoken', print_r($p, true));
        $st = new StoredToken();
        $st->token = 'czl1.payload.sig';
        $this->assertSame('czl1.payload.sig', $st->toArray()['token'], 'persisted with the token');
        $this->assertSame('czl1.payload.sig', StoredToken::fromArray($st->toArray())->token);
        $this->assertStringNotContainsString('payload', print_r($st, true));

        // A redaction placeholder in the store is "no token": a new one is minted.
        $station = $this->station();
        $store = new MemoryTokenStore();
        $c = $this->localClient(['mode' => Mode::LOCAL, 'tokenStore' => $store] + $this->pinned($station->url));
        $bad = new StoredToken();
        $bad->token = 'czl1.…';
        $bad->expiresAt = new \DateTimeImmutable('+20 days');
        $store->save($c->localState()->storeKey, $bad);
        $c->jobs->create(['text' => 'x']);
        $this->assertCount(1, self::cloud()->mints());
        $this->assertNotNull($c->local->storeError());
        $this->assertStringStartsWith('Bearer czl1.', $station->jobRequests()[0]['auth']);
    }

    // ---- Q3: same bytes for every re-send ---------------------------------------

    public function testSealedBodyIsSealedOnceAndResentUnchanged(): void
    {
        if (!function_exists('sodium_crypto_box_seal')) {
            self::markTestSkipped('ext-sodium');
        }
        $st = $this->station();
        // A retried 503, then 409 duplicate, then the job: three identical POSTs.
        // (A sealed job in cloud_first uses the cloud-mode create: S5, addendum 5.)
        self::cloud()->configure(['jobSeq' => [[503, 'unavailable'], [409, 'duplicate'], 'ok']]);
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST] + $this->pinned($st->url), null, ['maxRetries' => 2] + self::quiet());
        $this->assertSame(Job::VIA_CLOUD, $c->jobs->create(['text' => 'secret', 'sealed' => true, 'idempotencyKey' => 'k-q3'])->via);
        $jobs = self::cloud()->jobs();
        $this->assertCount(3, $jobs);
        $this->assertNotSame('', (string) ($jobs[0]['sealed_content'] ?? ''));
        $this->assertSame($jobs[0], $jobs[1]);
        $this->assertSame($jobs[0], $jobs[2]);
        $this->assertCount(1, array_filter(self::cloud()->requests(), static fn ($r) => $r['path'] === '/v1/public/server-key'), 'one key fetch');

        // Cloud mode: the client's own retries and the 409 re-sends reuse the bytes too.
        self::cloud()->reset();
        self::cloud()->configure(['jobSeq' => [[503, 'unavailable'], [409, 'duplicate'], 'ok']]);
        $cloud = $this->localClient([], null, ['maxRetries' => 2] + self::quiet());
        $cloud->jobs->create(['text' => 'secret', 'sealed' => true]);
        $jobs = self::cloud()->jobs();
        $this->assertCount(3, $jobs);
        $this->assertSame($jobs[0], $jobs[1]);
        $this->assertSame($jobs[0], $jobs[2]);
    }

    // ---- Q7(c): cloud 3xx is ambiguous -------------------------------------------

    public function testCloudRedirectIsAmbiguous(): void
    {
        $st = $this->station();
        self::cloud()->configure(['jobSeq' => [[307, 'moved'], 'ok']]);
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST] + $this->pinned($st->url));
        $this->assertSame('cloud-1', $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-3xx'])->id);
        $this->assertCount(2, self::cloud()->jobs());
        self::cloud()->reset();
        self::cloud()->configure(['jobSeq' => [[302, 'found'], [308, 'moved']]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-3xx2']));
        $this->assertInstanceOf(OutcomeUnknownException::class, $err);
        $this->assertCount(2, self::cloud()->jobs());
        $this->assertCount(0, $st->requests(), 'never local');
    }

    // ---- Q7(d): no PHP warnings from station sockets -----------------------------

    public function testNoWarningsFromStationSockets(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;

            return true;
        });
        try {
            $a = $this->station();
            $c = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($a->url));
            foreach (['reset', 'norecord', 'empty'] as $drop) {
                $a->configure(['drop' => ['POST /local/v1/jobs' => $drop, 'GET /local/v1/jobs' => $drop === 'reset' ? 'reset' : 'norecord']]);
                self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => "k-w-$drop"]));
            }
            $a->configure(['drop' => [], 'delay' => 1.0]);
            $slow = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'requestTimeout' => 0.2] + $this->pinned($a->url));
            self::thrown(fn () => $slow->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-w-slow']));
            $dead = self::deadUrl();
            $d = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$dead], 'fingerprints' => [$dead => str_repeat('ab', 32)]]);
            self::thrown(fn () => $d->jobs->create(['text' => 'x']));
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $warnings);
    }

    /**
     * Deterministic: a socket whose reads raise PHP warnings (as OpenSSL read
     * errors do on some builds, with paths in the text) must not leak them.
     */
    public function testSocketReadWarningsAreSwallowed(): void
    {
        if (!in_array('czwarn', stream_get_wrappers(), true)) {
            stream_wrapper_register('czwarn', WarningStream::class);
        }
        $sock = fopen('czwarn://station', 'r+');
        $this->assertTrue(is_resource($sock));
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;

            return true;
        });
        try {
            $m = new \ReflectionMethod(StationTransport::class, 'exchange');
            $m->setAccessible(true);
            $err = self::thrown(fn () => $m->invoke(new StationTransport(1.0, 1.0), $sock, '127.0.0.1', '7443', true, 'POST', '/local/v1/jobs', 'czd_x', '{}', microtime(true) + 5));
        } finally {
            restore_error_handler();
        }
        $this->assertInstanceOf(NoAnswerException::class, $err);
        $this->assertSame([], $warnings);
        $this->assertGreaterThan(0, WarningStream::$reads, "the read happened");
    }
}

/** A stream that accepts writes and raises a warning on every read (test helper). */
final class WarningStream
{
    public static int $reads = 0;
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return strlen($data);
    }

    public function stream_read(int $count): string|false
    {
        self::$reads++;
        trigger_error('fgets(): SSL: Connection reset by peer in /var/www/secret/path.php', E_USER_WARNING);

        return false;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}

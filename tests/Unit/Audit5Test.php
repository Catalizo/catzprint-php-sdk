<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Exception\ConflictException;
use CatzPrint\Exception\OutcomeUnknownException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Internal\Local\NoAnswerException;
use CatzPrint\Internal\Local\StationTransport;
use CatzPrint\Local\Mode;
use CatzPrint\Model\Job;
use CatzPrint\Resource\Jobs;
use CatzPrint\Tests\Support\LocalHelpers;
use CatzPrint\Tests\TestCase;

/**
 * SDK addendum 3 (audit round 5) regression tests for PHP: R3, R4, R5, R6,
 * R8 and C1–C5 (C6 has no PHP equivalent: there is no cancellation API).
 */
final class Audit5Test extends TestCase
{
    use LocalHelpers;

    /** @var list<float> */
    private array $sleeps = [];

    /** @return array<string, mixed> */
    private function pinned(string ...$urls): array
    {
        $pins = [];
        foreach ($this->stations as $s) {
            $pins[$s->url] = $s->fp;
        }

        return ['stationUrls' => $urls, 'fingerprints' => $pins];
    }

    /** @return array<string, mixed> client options recording sleeps */
    private function sleeper(): array
    {
        return ['sleeper' => function (float $s): void {
            $this->sleeps[] = $s;
        }];
    }

    // ---- R3: probe answers -----------------------------------------------------

    public function testProbe409DuplicateIsTheAnswer(): void
    {
        $a = $this->station();
        $b = $this->station();
        $a->configure(['drop' => ['POST /local/v1/jobs' => 'norecord'], 'fail' => ['GET /local/v1/jobs' => [409, 'duplicate']]]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($a->url, $b->url));
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-dup']));
        $this->assertInstanceOf(ConflictException::class, $err);
        $this->assertSame('duplicate', $err->errorCode);
        $this->assertCount(0, $b->requests(), 'no next station');
        $this->assertCount(0, self::cloud()->jobs(), 'no fallback');
        $this->assertCount(1, array_filter($a->requests(), static fn ($r) => $r['method'] === 'GET'), 'one probe');
    }

    public function testProbe404WithAnotherCodeKeepsProbing(): void
    {
        $a = $this->station();
        $a->configure(['drop' => ['POST /local/v1/jobs' => 'norecord'], 'fail' => ['GET /local/v1/jobs' => [404, 'route_not_found']]]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($a->url));
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-404x']));
        $this->assertInstanceOf(OutcomeUnknownException::class, $err);
        $this->assertCount(3, array_filter($a->requests(), static fn ($r) => $r['method'] === 'GET'));
        $this->assertCount(0, self::cloud()->jobs());
    }

    // ---- R4: cloud 409 duplicate --------------------------------------------------

    public function testCloud409DuplicateIsResent(): void
    {
        $st = $this->station();
        // Ambiguous 500, then "being created", then the job.
        self::cloud()->configure(['jobSeq' => [[500, 'internal'], [409, 'duplicate'], 'ok']]);
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST] + $this->pinned($st->url), null, $this->sleeper());
        $job = $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-r4']);
        $this->assertSame(Job::VIA_CLOUD, $job->via);
        $jobs = self::cloud()->jobs();
        $this->assertCount(3, $jobs);
        $this->assertSame([$jobs[0], $jobs[0]], [$jobs[1], $jobs[2]], 'identical request');
        $this->assertSame([1.0], $this->sleeps);
        $this->assertCount(0, $st->requests());

        // Cloud mode, a first-attempt 409 duplicate: re-sent too.
        self::cloud()->reset();
        $this->sleeps = [];
        self::cloud()->configure(['jobSeq' => [[409, 'duplicate'], 'ok']]);
        $cloud = $this->localClient([], null, $this->sleeper());
        $this->assertSame('cloud-1', $cloud->jobs->create(['text' => 'x'])->id);
        $jobs = self::cloud()->jobs();
        $this->assertCount(2, $jobs);
        $this->assertSame($jobs[0]['idempotency_key'], $jobs[1]['idempotency_key']);
    }

    public function testCloud409ThenNotDeliveredIsOutcomeUnknownNeverLocal(): void
    {
        $st = $this->station();
        self::cloud()->configure(['jobSeq' => [[500, 'internal'], [409, 'duplicate'], [503, 'unavailable']]]);
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST] + $this->pinned($st->url), null, $this->sleeper());
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-r4b']));
        $this->assertInstanceOf(OutcomeUnknownException::class, $err);
        $this->assertCount(0, $st->requests());

        // Still 409 after 3 re-sends: the cloud's answer.
        self::cloud()->reset();
        $this->sleeps = [];
        self::cloud()->configure(['jobSeq' => array_fill(0, 5, [409, 'duplicate'])]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-r4c']));
        $this->assertInstanceOf(ConflictException::class, $err);
        $this->assertCount(1 + Jobs::DUPLICATE_RESENDS, self::cloud()->jobs());
        $this->assertSame([1.0, 1.0, 1.0], $this->sleeps);
        $this->assertCount(0, $st->requests());
    }

    // ---- R5: no deadline check after the last chunk -------------------------------

    public function testDeadlineAfterTheFinalWriteIsNoAnswer(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $calls = 0;
        $start = microtime(true);
        // The first deadline check (before writing) passes; every later one
        // sees the deadline gone.
        StationTransport::$clockForTests = static function () use (&$calls, $start): float {
            return $calls++ === 0 ? $start : $start + 1000.0;
        };
        try {
            $m = new \ReflectionMethod(StationTransport::class, 'exchange');
            $m->setAccessible(true);
            $err = self::thrown(fn () => $m->invoke(new StationTransport(1.0, 1.0), $a, '127.0.0.1', '7443', true, 'POST', '/local/v1/jobs', 'czd_x', '{"text":"x"}', $start + 10.0));
        } finally {
            StationTransport::$clockForTests = null;
        }
        $this->assertInstanceOf(NoAnswerException::class, $err, 'delivered once the final write returned');
        $this->assertStringContainsString('POST /local/v1/jobs', (string) fread($b, 8192));
        fclose($a);
        fclose($b);
    }

    // ---- R6: Unicode whitespace and C1 controls ----------------------------------

    /** Port of scratchpad/audit5/sdk/phpkey.php. */
    public function testKeysWithUnicodeWhitespaceOrControlsAreRejected(): void
    {
        $m = new \ReflectionMethod(Jobs::class, 'checkIdempotencyKey');
        $m->setAccessible(true);
        $m->invoke(null, 'order-1-kitchen-r1');
        $m->invoke(null, 'café-order-1 r1'); // inner space and non-ASCII letters are fine
        foreach (["order-1\u{00A0}", "\u{3000}order-1", "order-1\u{0085}", 'order-1 ', "\u{FEFF}order-1", "order-1\u{2003}",
            "\u{2028}order-1", "or\u{0085}der", "or\u{009B}der", "or\x7fder", "or\tder", "or\xffder"] as $key) {
            $err = self::thrown(fn () => $m->invoke(null, $key));
            $this->assertInstanceOf(ValidationException::class, $err, (string) json_encode($key, JSON_INVALID_UTF8_SUBSTITUTE));
            $this->assertArrayHasKey('idempotency_key', $err->fields);
        }
        // All-blank (Unicode) counts as no key.
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::LOCAL] + $this->pinned($st->url));
        $c->jobs->create(['text' => 'x', 'idempotencyKey' => "\u{3000}\u{00A0}\u{FEFF}"]);
        $this->assertStringStartsWith('sdk-', $st->jobRequests()[0]['body']['idempotency_key']);
    }

    // ---- R8 / C4: effective uid without ext-posix -------------------------------

    public function testStoreOwnerCheckWithoutPosix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX only');
        }
        $dir = self::tempDir();
        try {
            $script = $dir . '/check.php';
            file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';
$s = new CatzPrint\Local\FileTokenStore($argv[1] . "/store/t.json");
echo function_exists("posix_geteuid") ? "posix" : "noposix", "\n";
$t = new CatzPrint\Local\StoredToken(); $t->token = "czd_x";
try { $s->save("k", $t); echo $s->load("k")->token, "\n"; } catch (Throwable $e) { echo "refused: ", $e->getMessage(), "\n"; }
');
            $run = static fn (): string => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=posix_geteuid ' . escapeshellarg($script) . ' ' . escapeshellarg($dir) . ' 2>&1');
            $out = $run();
            $this->assertSame("noposix\nczd_x\n", $out, 'the probe file gives the same uid as the store file');
            $this->assertSame(['t.json', 't.json.lock'], array_values(array_diff(scandir("$dir/store") ?: [], ['.', '..'])), 'probe file removed');
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                chown("$dir/store/t.json", 65534);
                $this->assertStringContainsString('refused: The token store path', $run());
                $this->assertStringContainsString('not owned by the current user', $run());
            }
        } finally {
            self::rmTree($dir);
        }
    }

    // ---- C1–C5 -------------------------------------------------------------------

    public function testLocalFirstReturnsTheCloud4xxItself(): void
    {
        self::cloud()->configure(['jobSeq' => [[422, 'no_printer']]]);
        $dead = self::deadUrl();
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$dead], 'fingerprints' => [$dead => str_repeat('ab', 32)]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(ValidationException::class, $err, 'not wrapped in StationUnreachableException');
        $this->assertSame('no_printer', $err->errorCode);
    }

    public function testAny5xxButGatewayCodesIsAmbiguous(): void
    {
        $st = $this->station();
        self::cloud()->configure(['jobSeq' => [[501, 'not_implemented'], 'ok']]);
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST] + $this->pinned($st->url));
        $this->assertSame(Job::VIA_CLOUD, $c->jobs->create(['text' => 'x'])->via);
        $this->assertCount(2, self::cloud()->jobs());
        self::cloud()->reset();
        self::cloud()->configure(['jobSeq' => [[599, 'weird'], [507, 'storage']]]);
        $this->assertInstanceOf(OutcomeUnknownException::class, self::thrown(fn () => $c->jobs->create(['text' => 'x'])));
        $this->assertCount(0, $st->requests());
    }

    public function testFailedOrgLookupMeansNotDelivered(): void
    {
        $st = $this->station();
        self::cloud()->configure(['keyStatus' => 500]);
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST, 'token' => 'czd_paired'] + $this->pinned($st->url), null, ['orgId' => null]);
        $job = $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-c3']);
        $this->assertSame(Job::VIA_LOCAL, $job->via, 'the job POST was never sent, so the station is safe');
        $this->assertCount(0, self::cloud()->jobs());
        $this->assertSame('k-c3', $st->jobRequests()[0]['body']['idempotency_key']);
    }

    public function testA2xxWithoutAJobIsNoAnswer(): void
    {
        // Station: 201 "{}" -> probe by key -> the recorded job.
        $st = $this->station();
        $st->configure(['drop' => ['POST /local/v1/jobs' => 'empty']]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($st->url));
        $job = $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-c5']);
        $this->assertStringStartsWith('lj_', $job->id);
        $this->assertSame('idempotency_key=k-c5', $st->requests()[1]['query']);
        $this->assertCount(0, self::cloud()->jobs());

        // Cloud: 201 "{}" -> one retry with the same key.
        self::cloud()->configure(['jobSeq' => ['empty', 'ok']]);
        $cf = $this->localClient(['mode' => Mode::CLOUD_FIRST] + $this->pinned($st->url));
        $this->assertSame('cloud-1', $cf->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-c5b'])->id);
        $this->assertCount(2, self::cloud()->jobs());
        self::cloud()->reset();
        self::cloud()->configure(['jobSeq' => ['empty', 'empty']]);
        $this->assertInstanceOf(OutcomeUnknownException::class, self::thrown(fn () => $cf->jobs->create(['text' => 'x'])));
    }
}

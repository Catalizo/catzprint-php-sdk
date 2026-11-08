<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Client;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ConnectionException;
use CatzPrint\Exception\OutcomeUnknownException;
use CatzPrint\Exception\ServerException;
use CatzPrint\Exception\StationUnreachableException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Internal\HttpClient;
use CatzPrint\Internal\Local\LocalState;
use CatzPrint\Internal\Local\Mdns;
use CatzPrint\Internal\Local\StationTarget;
use CatzPrint\Internal\Local\StationTransport;
use CatzPrint\Internal\RetryPolicy;
use CatzPrint\Local\FileTokenStore;
use CatzPrint\Local\Mode;
use CatzPrint\Local\StoredToken;
use CatzPrint\Model\Job;
use CatzPrint\Tests\Support\LocalHelpers;
use CatzPrint\Tests\TestCase;
use CatzPrint\Webhook;

/**
 * SDK addendum 2 (audit round 4) regression tests: SDK-1 ambiguous outcomes,
 * SDK-2 idempotency keys, SDK-4 secrets in traces, SDK-6 refresh threshold,
 * SDK-8 token store ownership/permissions, bounded mDNS collector.
 */
final class Audit4Test extends TestCase
{
    use LocalHelpers;

    /** @return array<string, mixed> */
    private function pinned(string ...$urls): array
    {
        $pins = [];
        foreach ($this->stations as $s) {
            $pins[$s->url] = $s->fp;
        }
        foreach ($urls as $u) {
            $pins[$u] ??= str_repeat('ab', 32);
        }

        return ['stationUrls' => $urls, 'fingerprints' => $pins];
    }

    // ---- SDK-1, local path ---------------------------------------------------

    public function testNotDeliveredTriesTheNextStation(): void
    {
        $st = $this->station();
        $dead = self::deadUrl();
        $c = $this->localClient(['mode' => Mode::LOCAL] + $this->pinned($dead, $st->url));
        $job = $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-nd']);
        $this->assertSame($st->url, $job->local?->stationUrl);
        $this->assertCount(1, $st->requests(), 'no probe for a request that never left');
    }

    public function testDeliveredNoAnswerThenFoundByKeyReturnsThatJob(): void
    {
        $st = $this->station();
        $st->configure(['drop' => ['POST /local/v1/jobs' => 'record']]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($st->url));
        $job = $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'order-5-kitchen-r1']);
        $this->assertSame(Job::VIA_LOCAL, $job->via);
        $this->assertStringStartsWith('lj_', $job->id);
        $this->assertSame('order-5-kitchen-r1', $job->idempotencyKey);
        $reqs = $st->requests();
        $this->assertCount(2, $reqs);
        $this->assertSame('GET', $reqs[1]['method']);
        $this->assertSame('idempotency_key=order-5-kitchen-r1', $reqs[1]['query']);
        $this->assertSame($reqs[0]['auth'], $reqs[1]['auth']);
        $this->assertCount(0, self::cloud()->jobs(), 'never sent anywhere else');
    }

    public function testDeliveredNoAnswerThen404ContinuesLikeNotDelivered(): void
    {
        $a = $this->station();
        $b = $this->station();
        $a->configure(['drop' => ['POST /local/v1/jobs' => 'norecord']]);
        $c = $this->localClient(['mode' => Mode::LOCAL] + $this->pinned($a->url, $b->url));
        $job = $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-404']);
        $this->assertSame($b->url, $job->local?->stationUrl);
        $this->assertSame('k-404', $b->jobRequests()[0]['body']['idempotency_key']);

        // local_first with one station: the cloud, same key.
        $lf = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($a->url));
        $this->assertSame(Job::VIA_CLOUD, $lf->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-404b'])->via);
        $this->assertSame('k-404b', self::cloud()->jobs()[0]['idempotency_key']);
    }

    public function testDeliveredNoAnswerAndNoProbeAnswerIsOutcomeUnknown(): void
    {
        $a = $this->station();
        $b = $this->station();
        $a->configure(['drop' => ['POST /local/v1/jobs' => 'record', 'GET /local/v1/jobs' => 'norecord']]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($a->url, $b->url));
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(OutcomeUnknownException::class, $err);
        $this->assertSame('outcome_unknown', $err->errorCode);
        $this->assertTrue($err->isOutcomeUnknown());
        $this->assertFalse($err->isStationUnreachable());
        $this->assertSame($a->url, $err->url);
        $this->assertStringStartsWith('sdk-', $err->idempotencyKey);
        $this->assertSame($a->jobRequests()[0]['body']['idempotency_key'], $err->idempotencyKey);
        $this->assertSame('The station may have printed this job. Retry later with the same idempotency key.', $err->getMessage());
        $probes = array_filter($a->requests(), static fn ($r) => $r['method'] === 'GET');
        $this->assertCount(LocalState::PROBE_TRIES, $probes);
        $this->assertCount(0, $b->requests(), 'no other station');
        $this->assertCount(0, self::cloud()->jobs(), 'no cloud fallback');
    }

    // ---- SDK-1, cloud path ---------------------------------------------------

    public function testCloud500IsRetriedOnceWithTheSameKey(): void
    {
        self::cloud()->configure(['jobSeq' => [[500, 'internal'], 'ok']]);
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST] + $this->pinned($st->url));
        $job = $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-500']);
        $this->assertSame(Job::VIA_CLOUD, $job->via);
        $jobs = self::cloud()->jobs();
        $this->assertCount(2, $jobs);
        $this->assertSame('k-500', $jobs[1]['idempotency_key']);
        $this->assertCount(0, $st->requests());
    }

    public function testCloudAmbiguousTwiceIsOutcomeUnknownNeverLocal(): void
    {
        $st = $this->station();
        foreach ([
            [[500, 'internal'], [503, 'unavailable']],        // 500, then not delivered
            [[500, 'internal'], [500, 'internal']],
            [['delay' => 1.0], ['delay' => 1.0]],             // delivered, timed out twice
        ] as $i => $seq) {
            self::cloud()->reset();
            self::cloud()->configure(['jobSeq' => $seq]);
            $c = $this->localClient(['mode' => Mode::CLOUD_FIRST, 'cloudTimeout' => 0.5] + $this->pinned($st->url));
            $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => "k-amb-$i"]));
            $this->assertInstanceOf(OutcomeUnknownException::class, $err, "case $i");
            $this->assertSame("k-amb-$i", $err->idempotencyKey);
            $this->assertSame(self::cloud()->url, $err->url);
            $this->assertCount(2, self::cloud()->jobs(), "case $i: one retry");
            $this->assertCount(0, $st->requests(), "case $i: never local");
        }
    }

    public function testCloudNotDeliveredGoesLocal(): void
    {
        self::cloud()->configure(['jobSeq' => [[502, 'bad_gateway']]]);
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST] + $this->pinned($st->url));
        $this->assertSame(Job::VIA_LOCAL, $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-502'])->via);
        $this->assertCount(1, self::cloud()->jobs());
        $this->assertSame('k-502', $st->jobRequests()[0]['body']['idempotency_key']);
    }

    public function testLocalFirstCloudLeg(): void
    {
        $dead = self::deadUrl();
        // 500 then ok: one retry, the cloud's job.
        self::cloud()->configure(['jobSeq' => [[500, 'internal'], 'ok']]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST] + $this->pinned($dead), null, ['maxRetries' => 3]);
        $this->assertSame(Job::VIA_CLOUD, $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-lf'])->via);
        $this->assertCount(2, self::cloud()->jobs());
        // 502: StationUnreachable carrying the cloud error, no retry.
        self::cloud()->reset();
        self::cloud()->configure(['jobSeq' => [[502, 'bad_gateway']]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-lf2']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertInstanceOf(ServerException::class, $err->cloudError);
        $this->assertCount(1, self::cloud()->jobs());
        // Ambiguous twice: OutcomeUnknown.
        self::cloud()->reset();
        self::cloud()->configure(['jobSeq' => [[500, 'internal'], [504, 'gateway_timeout']]]);
        $this->assertInstanceOf(OutcomeUnknownException::class, self::thrown(fn () => $c->jobs->create(['text' => 'x'])));
    }

    public function testHttpClientMarksDeliveredFailures(): void
    {
        $retry = new RetryPolicy(0);
        $dead = new HttpClient('cz_test_x', self::deadHttp(), 1.0, $retry);
        $e = self::thrown(fn () => $dead->request('POST', '/v1/orgs/org1/jobs', [], ['idempotency_key' => 'k']));
        $this->assertInstanceOf(ConnectionException::class, $e);
        $this->assertFalse($e->requestDelivered());

        self::cloud()->configure(['jobSeq' => [['delay' => 1.0]]]);
        $slow = new HttpClient('cz_test_x', self::cloud()->url, 0.4, $retry);
        $e = self::thrown(fn () => $slow->request('POST', '/v1/orgs/org1/jobs', [], ['idempotency_key' => 'k']));
        $this->assertInstanceOf(ConnectionException::class, $e);
        $this->assertSame('timeout', $e->errorCode);
        $this->assertTrue($e->requestDelivered());
    }

    // ---- SDK-2 -----------------------------------------------------------------

    public function testIdempotencyKeys(): void
    {
        $st = $this->station();
        // Blank = no key.
        $local = $this->localClient(['mode' => Mode::LOCAL] + $this->pinned($st->url));
        $local->jobs->create(['text' => 'x', 'idempotencyKey' => "  \t"]);
        $this->assertStringStartsWith('sdk-', $st->jobRequests()[0]['body']['idempotency_key']);
        $cloud = $this->localClient([]);
        $cloud->jobs->create(['text' => 'x', 'idempotencyKey' => '']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4/', self::cloud()->jobs()[0]['idempotency_key']);

        // Whitespace around or control characters: refused, nothing sent, never changed.
        foreach ([' order-1', 'order-1 ', "order-1\n", "\torder-1", "or\x00der", "or\x1bder", "order\x7f"] as $key) {
            foreach ([$local, $cloud] as $c) {
                $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => $key]));
                $this->assertInstanceOf(ValidationException::class, $err, json_encode($key) ?: '');
                $this->assertSame('invalid', $err->errorCode);
                $this->assertArrayHasKey('idempotency_key', $err->fields);
            }
        }
        $this->assertCount(1, $st->jobRequests());
        $this->assertCount(1, self::cloud()->jobs());
        // A normal key is sent unchanged.
        $local->jobs->create(['text' => 'x', 'idempotencyKey' => 'order-1042-kitchen-r1']);
        $this->assertSame('order-1042-kitchen-r1', $st->jobRequests()[1]['body']['idempotency_key']);
    }

    // ---- SDK-4 -----------------------------------------------------------------

    /** Port of scratchpad/audit4/sdk/phpx/trace.php: no token in exception trace args. */
    public function testSecretsNeverInTraceArgs(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('#[\SensitiveParameter] needs PHP 8.2');
        }
        $old = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $secret = 'czl1.SECRETPAYLOAD.SIGNATURE';
            $traces = [];
            $tr = new StationTransport(0.5, 1.0);
            $traces[] = self::thrown(fn () => $tr->request(new StationTarget('https://127.0.0.1:1', str_repeat('ab', 32)), 'POST', '/local/v1/jobs', $secret, '{}'));

            $dead = self::deadUrl();
            $c = $this->localClient(['mode' => Mode::LOCAL, 'token' => $secret] + $this->pinned($dead), 'down');
            $e = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
            $traces[] = $e;
            if ($e instanceof StationUnreachableException) {
                $traces[] = $e->attempts[0]->error;
            }
            $traces[] = self::thrown(fn () => new Client('cz_test_SECRETAPIKEY', ['local' => ['mode' => 'bogus', 'token' => $secret]]));
            $traces[] = self::thrown(fn () => Webhook::verify('{}', 't=1,v1=00', 'whsec_SECRETWEBHOOK', 300, 1));
            $traces[] = self::thrown(fn () => $c->local->pair('https://127.0.0.1:1', '12345678', 'Till', str_repeat('ab', 32)));

            foreach ($traces as $i => $t) {
                $this->assertNotNull($t, "case $i threw");
                for ($x = $t; $x !== null; $x = $x->getPrevious()) {
                    // The SDK's own frames (the test's closures capture the secret themselves).
                    $sdk = array_filter($x->getTrace(), static fn ($f) => str_starts_with($f['class'] ?? '', 'CatzPrint\\') && !str_starts_with($f['class'] ?? '', 'CatzPrint\\Tests\\'));
                    $this->assertNotSame([], $sdk, "case $i has SDK frames");
                    $dump = print_r(array_map(static fn ($f) => $f['args'] ?? [], $sdk), true) . $x->getMessage();
                    foreach (['SECRETPAYLOAD', 'SECRETAPIKEY', 'SECRETWEBHOOK', '12345678'] as $needle) {
                        $this->assertStringNotContainsString($needle, $dump, "case $i: $needle in " . get_class($x));
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $old);
        }
    }

    // ---- SDK-6 -----------------------------------------------------------------

    public function testRefreshThresholdIsMinOf7DaysAndHalfTheTtl(): void
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $tok = static fn (int $iat, int $exp): string => 'czl1.' . $b64((string) json_encode(['jti' => 'j', 'iat' => $iat, 'exp' => $exp])) . '.c2ln';
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => ['https://192.168.1.2:7443'], 'tokenTtlDays' => 2]);
        $m = new \ReflectionMethod(LocalState::class, 'refreshBefore');
        $m->setAccessible(true);
        $l = $c->localState();
        $this->assertSame(7 * 86400.0, $m->invoke($l, $tok(0, 30 * 86400)), '30-day token: 7 days');
        $this->assertSame(43200.0, $m->invoke($l, $tok(0, 86400)), '1-day token: 12 hours');
        $this->assertSame(86400.0, $m->invoke($l, 'czd_x'), 'no claims: tokenTtlDays (2) / 2');

        // End to end: a 1-day token with 13 h left is kept, with 11 h left renewed.
        $st = $this->station();
        foreach ([13 => 0, 11 => 1] as $hoursLeft => $wantMints) {
            self::cloud()->reset();
            $store = new \CatzPrint\Local\MemoryTokenStore();
            $cl = $this->localClient(['mode' => Mode::LOCAL, 'tokenStore' => $store, 'tokenTtlDays' => 1] + $this->pinned($st->url));
            $s = new StoredToken();
            $s->token = $tok(time() - 86400 + $hoursLeft * 3600, time() + $hoursLeft * 3600);
            $s->expiresAt = (new \DateTimeImmutable())->modify("+$hoursLeft hours");
            $s->stationsUpdatedAt = new \DateTimeImmutable();
            $store->save($cl->localState()->storeKey, $s);
            $cl->jobs->create(['text' => 'x']);
            $this->assertCount($wantMints, self::cloud()->mints(), "$hoursLeft h left");
        }
    }

    // ---- SDK-8 -----------------------------------------------------------------

    public function testFileTokenStoreRefusesUnsafePaths(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX only');
        }
        $dir = self::tempDir();
        try {
            $path = "$dir/store/t.json";
            $s = new FileTokenStore($path);
            $s->save('k', new StoredToken());
            $this->assertNotNull($s->load('k'));

            chmod($path, 0o666);
            $this->assertStringContainsString('writable by group or others', (string) self::thrown(fn () => $s->load('k'))?->getMessage());
            $this->assertNotNull(self::thrown(fn () => $s->save('k', new StoredToken())));
            chmod($path, 0o600);
            chmod("$dir/store", 0o777);
            $this->assertNotNull(self::thrown(fn () => $s->load('k')), 'group/world-writable directory');
            chmod("$dir/store", 0o700);
            chmod("$path.lock", 0o620);
            $this->assertNotNull(self::thrown(fn () => $s->load('k')), 'group-writable lock file');
            chmod("$path.lock", 0o600);
            $this->assertNotNull($s->load('k'));

            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                chown($path, 65534);
                $this->assertStringContainsString('not owned by the current user', (string) self::thrown(fn () => $s->load('k'))?->getMessage());
                chown($path, 0);
            }

            // Through a client: refused, reported, printing still works.
            chmod($path, 0o664);
            $before = file_get_contents($path);
            $st = $this->station();
            $c = $this->localClient(['mode' => Mode::LOCAL, 'tokenStore' => $s] + $this->pinned($st->url));
            $this->assertSame(Job::VIA_LOCAL, $c->jobs->create(['text' => 'x'])->via);
            $this->assertNotNull($c->local->storeError());
            $this->assertSame($before, file_get_contents($path), 'the unsafe file was neither read nor written');
        } finally {
            self::rmTree($dir);
        }
    }

    // ---- mDNS bounds -----------------------------------------------------------

    public function testMdnsCollectorIsBounded(): void
    {
        $name = static fn (string $n): string => Mdns::encodeName($n);
        $rr = static fn (string $n, int $type, string $rdata): string => $name($n) . pack('nnNn', $type, 1, 120, strlen($rdata)) . $rdata;
        $col = new Mdns();
        for ($i = 0; $i < 400; $i++) {
            $inst = "s$i._catzprint._tcp.local.";
            $txt = implode('', array_map(static fn (string $s): string => chr(strlen($s)) . $s, ['v=1', "id=s$i", 'port=7443']));
            $a = '';
            for ($j = 0; $j < 40; $j++) {
                $a .= $rr("h$i.local.", 1, (string) inet_pton("10.0.0.$j"));
            }
            $col->add(pack('n6', 0, 0x8400, 0, 2 + 40, 0, 0) . $rr($inst, 16, $txt) . $rr($inst, 33, pack('nnn', 0, 0, 7443) . $name("h$i.local.")) . $a, '10.0.0.1');
        }
        $found = $col->stations();
        $this->assertCount(Mdns::MAX_NAMES, $found);
        foreach ($found as $f) {
            $this->assertLessThanOrEqual(Mdns::MAX_ADDRESSES, count($f->addresses));
        }
    }

    public function testCatzPrintExceptionHelper(): void
    {
        $this->assertFalse((new CatzPrintException('x'))->isOutcomeUnknown());
        $e = new OutcomeUnknownException('https://192.168.1.2:7443', 'k', new ConnectionException('t'));
        $this->assertTrue($e->isOutcomeUnknown());
        $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
    }
}

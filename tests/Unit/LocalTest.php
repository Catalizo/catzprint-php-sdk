<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Client;
use CatzPrint\Exception\AuthenticationException;
use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ConflictException;
use CatzPrint\Exception\ConnectionException;
use CatzPrint\Exception\OutcomeUnknownException;
use CatzPrint\Exception\OutOfPrintsException;
use CatzPrint\Exception\PermissionException;
use CatzPrint\Exception\PrinterException;
use CatzPrint\Exception\RateLimitException;
use CatzPrint\Exception\ServerException;
use CatzPrint\Exception\StationUnreachableException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Local\FileTokenStore;
use CatzPrint\Local\Mode;
use CatzPrint\Model\Job;
use CatzPrint\Model\LocalJob;
use CatzPrint\Tests\Support\LocalHelpers;
use CatzPrint\Tests\TestCase;

/**
 * Local printing: modes, fallback rules, tokens and helpers against a fake
 * TLS station and a fake cloud.
 */
final class LocalTest extends TestCase
{
    use LocalHelpers;

    public function testLocalModePrintsOnStation(): void
    {
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::LOCAL, 'locationId' => 'loc1', 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $job = $c->jobs->create([
            'role' => 'kitchen',
            'commands' => [['type' => 'text', 'text' => 'ALLERGY', 'invert' => true], ['type' => 'cut']],
            'copies' => 2,
        ]);
        $this->assertSame(Job::VIA_LOCAL, $job->via);
        $this->assertTrue($job->printedLocally);
        $this->assertStringStartsWith('lj_', $job->id);
        $this->assertSame('queued', $job->status);
        $this->assertSame('commands', $job->kind);
        $this->assertSame('local', $job->source);
        $this->assertSame(2, $job->copies);
        $this->assertSame('p-kitchen', $job->printerId);
        $this->assertTrue($job->live, 'minted live token');
        $this->assertInstanceOf(LocalJob::class, $job->local);
        $this->assertSame($st->url, $job->local->stationUrl);

        $reqs = $st->jobRequests();
        $this->assertCount(1, $reqs);
        $body = $reqs[0]['body'];
        $this->assertSame('kitchen', $body['role']);
        $this->assertSame(2, $body['copies']);
        $this->assertSame('loc1', $body['location_id'], 'the configured location is used');
        $this->assertSame('ALLERGY', $body['commands'][0]['text']);
        $this->assertMatchesRegularExpression('/^sdk-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['idempotency_key']);
        $this->assertSame($body['idempotency_key'], $job->idempotencyKey);
        $this->assertStringStartsWith('Bearer czl1.', $reqs[0]['auth']);
        $this->assertSame('127.0.0.1:' . $st->port, $reqs[0]['host']);

        $mints = self::cloud()->mints();
        $this->assertCount(1, $mints);
        $this->assertSame(['loc1'], $mints[0]['location_ids'], 'minted token bound to the location');
        $this->assertSame('Hub', $mints[0]['device_name']);
        $this->assertCount(0, self::cloud()->jobs());
    }

    public function testLocalFirstFallsBackWhenStationUnreachable(): void
    {
        $dead = self::deadUrl();
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$dead], 'fingerprints' => [$dead => str_repeat('ab', 32)]]);
        $job = $c->jobs->create(self::kitchen('order-1042-kitchen-r1'));
        $this->assertSame(Job::VIA_CLOUD, $job->via);
        $this->assertSame('cloud-1', $job->id);
        $this->assertFalse($job->printedLocally);
        $this->assertSame('order-1042-kitchen-r1', self::cloud()->jobs()[0]['idempotency_key']);
    }

    public function testGeneratedKeyIsReusedAcrossFallback(): void
    {
        $st = $this->station();
        $st->configure(['fail' => ['POST /local/v1/jobs' => [502, 'bad_gateway']]]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $job = $c->jobs->create(['text' => "hello\n"]);
        $this->assertSame(Job::VIA_CLOUD, $job->via);
        $stationKey = $st->jobRequests()[0]['body']['idempotency_key'];
        $this->assertStringStartsWith('sdk-', $stationKey);
        $this->assertSame($stationKey, self::cloud()->jobs()[0]['idempotency_key']);
    }

    public function testLocalFirstFallbackRules(): void
    {
        $cases = [
            // status, code, falls back?, expected class
            [502, 'bad_gateway', true, null],
            [504, 'gateway_timeout', true, null],
            [503, 'local_disabled', true, null],
            [503, '', true, null],
            [503, 'printer_offline', false, PrinterException::class],
            [503, 'out_of_paper', false, PrinterException::class],
            [503, 'cover_open', false, PrinterException::class],
            [503, 'printer_error', false, PrinterException::class],
            [422, 'no_printer', false, ValidationException::class],
            [400, 'invalid', false, ValidationException::class],
            [422, 'unsupported_locally', false, ValidationException::class],
            [402, 'offline_allowance_exhausted', false, OutOfPrintsException::class],
            [403, 'device_not_approved', false, PermissionException::class],
            [403, 'forbidden_location', false, PermissionException::class],
            [409, 'duplicate', false, ConflictException::class],
            [429, 'rate_limited', false, RateLimitException::class],
        ];
        foreach ($cases as [$status, $code, $fallback, $class]) {
            self::cloud()->reset();
            $st = $this->station();
            $st->configure(['fail' => ['POST /local/v1/jobs' => [$status, $code]]]);
            $c = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
            $key = "k-$status-$code";
            $job = null;
            $err = self::thrown(function () use ($c, $key, &$job): void {
                $job = $c->jobs->create(self::kitchen($key));
            });
            $what = "$status $code";
            if ($fallback) {
                $this->assertNull($err, "$what: " . ($err?->getMessage() ?? ''));
                $this->assertSame(Job::VIA_CLOUD, $job?->via, $what);
                $this->assertCount(1, self::cloud()->jobs(), $what);
                $this->assertSame($key, self::cloud()->jobs()[0]['idempotency_key'], $what);
                continue;
            }
            $this->assertInstanceOf($class, $err, $what);
            $this->assertSame($code, $err->errorCode, $what);
            $this->assertFalse($err->isStationUnreachable(), $what);
            $this->assertCount(0, self::cloud()->jobs(), "$what must not fall back");
        }
        $offline = new PrinterException('x', 503, 'printer_offline');
        $this->assertTrue($offline->isPrinterOffline());
        $this->assertTrue((new PrinterException('x', 503, 'out_of_paper'))->isOutOfPaper());
        $this->assertTrue((new PrinterException('x', 503, 'cover_open'))->isCoverOpen());
        $this->assertTrue((new OutOfPrintsException('x', 402, 'offline_allowance_exhausted'))->isOfflineAllowanceExhausted());
    }

    public function testLocalModeReturnsPrinterErrors(): void
    {
        $st = $this->station();
        $st->configure(['fail' => ['POST /local/v1/jobs' => [503, 'out_of_paper']]]);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(PrinterException::class, $err);
        $this->assertTrue($err->isOutOfPaper());
        $this->assertSame(503, $err->status);
    }

    public function testStationTimeoutAfterDeliveryIsOutcomeUnknown(): void
    {
        // The request reached the station, the answer didn't come back, and
        // the key probes time out too: maybe printed -> no fallback.
        $st = $this->station();
        $st->configure(['delay' => 1.5]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp], 'requestTimeout' => 0.4]);
        $t0 = microtime(true);
        $err = self::thrown(fn () => $c->jobs->create(self::kitchen('k-timeout')));
        $this->assertInstanceOf(OutcomeUnknownException::class, $err);
        $this->assertSame('k-timeout', $err->idempotencyKey);
        $this->assertCount(0, self::cloud()->jobs());
        $this->assertLessThanOrEqual(5.5, microtime(true) - $t0, 'probes are bounded by 5 s');
    }

    public function testTlsHandshakeTimeoutIsUnreachable(): void
    {
        // Accepts TCP (kernel backlog) but never speaks TLS.
        $hole = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotNull($hole);
        $name = (string) stream_socket_get_name($hole, false);
        $url = 'https://' . $name;
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$url], 'fingerprints' => [$url => str_repeat('ab', 32)], 'connectTimeout' => 0.5]);
        $t0 = microtime(true);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        fclose($hole);
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertSame([$url], $err->urls());
        $this->assertInstanceOf(ConnectionException::class, $err->attempts[0]->error);
        $this->assertSame('timeout', $err->attempts[0]->error->errorCode);
        $this->assertLessThanOrEqual(1.5, microtime(true) - $t0);
    }

    public function testStationOrderTriesNextOnUnreachable(): void
    {
        $st = $this->station();
        $dead = self::deadUrl();
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$dead, $st->url], 'fingerprints' => [$dead => $st->fp, $st->url => $st->fp]]);
        $job = $c->jobs->create(['text' => 'x']);
        $this->assertSame($st->url, $job->local?->stationUrl);
    }

    public function testLocal401RemintsOnceThenSucceeds(): void
    {
        $st = $this->station();
        $st->configure(['accept' => ['jti-2']]); // only the second minted token
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $job = $c->jobs->create(self::kitchen('k1'));
        $this->assertSame(Job::VIA_LOCAL, $job->via);
        $this->assertCount(2, self::cloud()->mints());
        $this->assertCount(2, $st->jobRequests());
        $this->assertSame('k1', $st->jobRequests()[1]['body']['idempotency_key'], 'same key after re-mint');
    }

    public function testLocal401TwiceFallsBackOrFails(): void
    {
        foreach ([Mode::LOCAL_FIRST, Mode::LOCAL] as $mode) {
            self::cloud()->reset();
            $st = $this->station();
            $st->configure(['accept' => []]);
            $c = $this->localClient(['mode' => $mode, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
            $job = null;
            $err = self::thrown(function () use ($c, &$job): void {
                $job = $c->jobs->create(self::kitchen('k401'));
            });
            $this->assertCount(2, self::cloud()->mints(), "$mode: exactly one re-mint");
            $this->assertCount(2, $st->jobRequests(), $mode);
            if ($mode === Mode::LOCAL_FIRST) {
                $this->assertNull($err);
                $this->assertSame(Job::VIA_CLOUD, $job?->via);
                $this->assertSame('k401', self::cloud()->jobs()[0]['idempotency_key']);
                continue;
            }
            $this->assertInstanceOf(AuthenticationException::class, $err);
            $this->assertSame('token_expired', $err->errorCode);
            $this->assertCount(0, self::cloud()->jobs());
        }
    }

    public function testLocal401WithCloudDownFallsBackToCloudError(): void
    {
        $st = $this->station();
        $st->configure(['accept' => []]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $this->assertStringStartsWith('czl1.', $c->local->token());
        self::cloud()->configure(['down' => true]);
        $err = self::thrown(fn () => $c->jobs->create(self::kitchen('k')));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertInstanceOf(ServerException::class, $err->cloudError);
        $this->assertSame($err->cloudError, $err->getPrevious());
        $this->assertCount(1, $st->jobRequests());
    }

    public function testRemintNeverOnRevokedOrUnauthenticated(): void
    {
        foreach (['device_revoked', 'unauthenticated'] as $code) {
            self::cloud()->reset();
            $st = $this->station();
            $st->configure(['accept' => [], 'rejectCode' => $code]);
            $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
            $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
            $this->assertInstanceOf(AuthenticationException::class, $err, $code);
            $this->assertSame($code, $err->errorCode);
            $this->assertCount(1, self::cloud()->mints(), "re-minted on $code");
            $this->assertCount(1, $st->jobRequests());
        }
    }

    public function testRemintRateLimitIsPersistedPerStoreKey(): void
    {
        $st = $this->station();
        $st->configure(['accept' => []]);
        $dir = self::tempDir();
        $store = new FileTokenStore("$dir/t.json");
        $cfg = ['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp], 'tokenStore' => $store];
        try {
            for ($i = 0; $i < 3; $i++) { // three PHP requests within 10 minutes
                $c = $this->localClient($cfg);
                $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
                $this->assertSame('token_expired', $err instanceof CatzPrintException ? $err->errorCode : null);
            }
            $this->assertCount(2, self::cloud()->mints(), 'the first mint + one re-mint');
            // After 10 minutes one more re-mint is allowed.
            $c = $this->localClient($cfg);
            $c->localState()->setClock(static fn (): float => microtime(true) + 11 * 60);
            self::thrown(fn () => $c->jobs->create(['text' => 'x']));
            $this->assertCount(3, self::cloud()->mints());
        } finally {
            self::rmTree($dir);
        }
    }

    public function testCloudFirst503GoesLocalWithTheSameKey(): void
    {
        self::cloud()->configure(['jobStatus' => 503, 'jobCode' => 'unavailable']);
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]], null, ['maxRetries' => 3]);
        $job = $c->jobs->create(['text' => 'x']);
        $this->assertSame(Job::VIA_LOCAL, $job->via);
        $cloudJobs = self::cloud()->jobs();
        $this->assertCount(1, $cloudJobs, 'cloud_first makes one cloud attempt before falling back');
        $this->assertStringStartsWith('sdk-', $cloudJobs[0]['idempotency_key']);
        $this->assertSame($cloudJobs[0]['idempotency_key'], $st->jobRequests()[0]['body']['idempotency_key']);
    }

    public function testCloudFirstNetworkErrorGoesLocal(): void
    {
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp], 'token' => 'czd_paired'], 'down');
        $job = $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'order-7']);
        $this->assertSame(Job::VIA_LOCAL, $job->via);
        $req = $st->jobRequests()[0];
        $this->assertSame('order-7', $req['body']['idempotency_key']);
        $this->assertSame('Bearer czd_paired', $req['auth']);
    }

    public function testCloudFirst4xxIsReturned(): void
    {
        foreach ([[422, 'no_printer'], [402, 'out_of_prints'], [429, 'rate_limited'], [401, 'invalid_api_key'], [403, 'forbidden']] as [$status, $code]) {
            self::cloud()->reset();
            self::cloud()->configure(['jobStatus' => $status, 'jobCode' => $code]);
            $st = $this->station();
            $c = $this->localClient(['mode' => Mode::CLOUD_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
            $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
            $this->assertInstanceOf(CatzPrintException::class, $err);
            $this->assertSame($code, $err->errorCode);
            $this->assertCount(0, $st->requests(), "$status: no station request");
        }
    }

    public function testCloudFirstBothDown(): void
    {
        self::cloud()->configure(['jobStatus' => 503, 'jobCode' => 'unavailable']);
        $dead = self::deadUrl();
        $c = $this->localClient(['mode' => Mode::CLOUD_FIRST, 'stationUrls' => [$dead], 'fingerprints' => [$dead => str_repeat('0', 64)]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertTrue($err->isStationUnreachable());
        $this->assertInstanceOf(ServerException::class, $err->cloudError);
        $this->assertSame([$dead], $err->urls());
        $this->assertStringContainsString('cloud fallback failed too', $err->getMessage());
    }

    public function testUnsupportedLocally(): void
    {
        $st = $this->station();
        $pdf = "%PDF-1.4 fake";
        $local = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $err = self::thrown(fn () => $local->printPdf($pdf));
        $this->assertInstanceOf(ValidationException::class, $err);
        $this->assertSame('unsupported_locally', $err->errorCode);
        foreach ([['text' => 'x', 'sealed' => true], ['text' => 'x', 'scheduledFor' => '2030-01-01T00:00:00Z'], ['content' => "\x89PNG...."]] as $p) {
            $e = self::thrown(fn () => $local->jobs->create($p));
            $this->assertSame('unsupported_locally', $e instanceof CatzPrintException ? $e->errorCode : null);
        }

        $lf = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $job = $lf->printPdf($pdf);
        $this->assertSame(Job::VIA_CLOUD, $job->via);
        $this->assertCount(0, $st->requests(), 'PDFs go straight to the cloud');

        // Raw ESC/POS prints locally (base64, kind escpos).
        $job = $local->jobs->create(['content' => "\x1b@hello\x1dV\x00"]);
        $this->assertSame(Job::VIA_LOCAL, $job->via);
        $body = $st->jobRequests()[0]['body'];
        $this->assertSame('escpos', $body['kind']);
        $this->assertSame(base64_encode("\x1b@hello\x1dV\x00"), $body['content']);
    }

    public function testWaitIsSentToTheStation(): void
    {
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp], 'wait' => 5]);
        $job = $c->jobs->create(['text' => 'x']);
        $this->assertSame('wait=5', $st->jobRequests()[0]['query']);
        $this->assertSame('printed', $job->status);
        $this->assertTrue($job->isPrinted());
        $this->assertNotNull($job->printedAt);
    }

    public function testSameKeyTwiceReturnsTheSameLocalJob(): void
    {
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $a = $c->jobs->create(self::kitchen('order-1-kitchen-r1'));
        $b = $c->jobs->create(self::kitchen('order-1-kitchen-r1'));
        $this->assertSame($a->id, $b->id);
    }

    public function testConfigValidation(): void
    {
        $bad = [
            ['mode' => 'offline', 'stationUrls' => ['https://192.168.1.2:7443']],
            ['mode' => Mode::LOCAL],                                              // no URLs, no discover
            ['mode' => Mode::LOCAL, 'stationUrls' => ['https://192.168.1.2:7443']], // no locationId / allLocations
            ['mode' => Mode::LOCAL, 'discover' => true, 'token' => 'abc'],
            ['mode' => Mode::LOCAL, 'discover' => true, 'token' => "czd_x\r\nX-Evil: 1"],
            ['mode' => Mode::LOCAL, 'stationUrls' => ['http://192.168.1.2:7443'], 'allLocations' => true],
            ['mode' => Mode::LOCAL, 'stationUrls' => ['https://u:p@192.168.1.2:7443'], 'allLocations' => true],
            ['mode' => Mode::LOCAL, 'stationUrls' => ['https://192.168.1.2:7443/x'], 'allLocations' => true],
            ['mode' => Mode::LOCAL, 'discover' => true, 'allLocations' => true, 'fingerprints' => ['192.168.1.2' => 'nothex']],
            ['mode' => Mode::LOCAL, 'discover' => true, 'allLocations' => true, 'tokenTtlDays' => 31],
            ['mode' => Mode::LOCAL, 'discover' => true, 'allLocations' => true, 'wait' => 31],
            ['mode' => Mode::LOCAL, 'discover' => true, 'allLocations' => true, 'bogus' => 1],
        ];
        foreach ($bad as $i => $local) {
            $err = self::thrown(fn () => new Client('cz_test_x', ['local' => $local]));
            $this->assertInstanceOf(\InvalidArgumentException::class, $err, "case $i");
        }
        $ok = new Client('cz_test_x', ['local' => ['mode' => Mode::LOCAL, 'locationId' => 'loc1', 'discover' => true]]);
        $this->assertSame(Mode::LOCAL, $ok->local->mode());
        $this->assertSame(Mode::CLOUD, (new Client('cz_test_x'))->local->mode());
    }

    public function testCloudModeUnchanged(): void
    {
        $st = $this->station();
        $c = $this->localClient(['stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $job = $c->jobs->create(['text' => 'x']);
        $this->assertSame(Job::VIA_CLOUD, $job->via);
        $this->assertCount(0, $st->requests());
        $this->assertCount(0, self::cloud()->mints());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-/', self::cloud()->jobs()[0]['idempotency_key'], 'plain UUID in cloud mode');
    }

    public function testLocalHelpers(): void
    {
        $st = $this->station();
        self::cloud()->configure(['stations' => [[
            'station_id' => 'st1', 'name' => 'Kitchen PC', 'location_id' => 'loc1', 'connected' => true, 'local_enabled' => true,
            'port' => $st->port, 'addresses' => ['127.0.0.1'], 'urls' => [$st->url], 'tls_mode' => 'self_signed',
            'cert_fingerprint_sha256' => $st->fp, 'updated_at' => '2026-11-07T00:00:00Z',
        ]]]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'locationId' => 'loc1', 'discover' => true]);

        $status = $c->local->status();
        $this->assertSame('st1', $status->stationId);
        $this->assertSame(3, $status->unsynced);
        $this->assertSame(1997, $status->allowancePrintsLeft);
        $this->assertSame('ready', $status->printers[0]->status);
        $this->assertNull($status->lastCloudContact);
        $this->assertSame($st->url, $status->stationUrl);

        $job = $c->jobs->create(self::kitchen('order-9-kitchen-r1'));
        $this->assertSame('st1', $job->stationId, 'station id from the cloud endpoint list');
        $got = $c->jobs->get($job->id);
        $this->assertSame(Job::VIA_LOCAL, $got->via);
        $this->assertSame('printed', $got->status);
        $this->assertSame('printed', $c->jobs->waitFor($job->id, 5, 0.1)->status);
        $nf = self::thrown(fn () => $c->jobs->get('lj_ffffffffffffffff'));
        $this->assertSame('not_found', $nf instanceof CatzPrintException ? $nf->errorCode : null);

        $info = $c->local->info($st->url);
        $this->assertSame('st1', $info->stationId);
        $this->assertSame($st->fp, $info->certFingerprintSha256);

        $found = $c->local->discover();
        $this->assertCount(1, $found);
        $this->assertTrue($found[0]->fromCloud);
        $this->assertSame([$st->url], $found[0]->urls);

        $this->assertStringStartsWith('czl1.', $c->local->token());
        $c->local->refresh();
        $this->assertNull($c->local->storeError());

        $lt = $c->localTokens->create(['deviceName' => 'Till 3', 'locationIds' => ['loc1'], 'ttlDays' => 7]);
        $this->assertSame('jti-' . count(self::cloud()->mints()), $lt->tokenId);
        $last = self::cloud()->mints()[count(self::cloud()->mints()) - 1];
        $this->assertSame(['device_name' => 'Till 3', 'location_ids' => ['loc1'], 'ttl_days' => 7], $last);
        $c->localTokens->revoke($lt->tokenId);
        $eps = $c->localEndpoints->list('loc1');
        $this->assertSame('st1', $eps[0]->stationId);
        $reqs = self::cloud()->requests();
        $this->assertContains('DELETE /v1/orgs/org1/local-tokens/' . $lt->tokenId, array_map(static fn ($r) => $r['method'] . ' ' . $r['path'], $reqs));
        $this->assertSame('location_id=loc1', $reqs[count($reqs) - 1]['query']);
    }

    public function testPairedDeviceWithoutApiKey(): void
    {
        $prev = getenv('CATZPRINT_API_KEY');
        putenv('CATZPRINT_API_KEY');
        try {
            $st = $this->station();
            $pins = [$st->url => $st->fp];
            $err = self::thrown(fn () => new Client(null, ['local' => ['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => $pins, 'token' => 'czd_paired']]));
            $this->assertInstanceOf(\InvalidArgumentException::class, $err, 'local_first without an API key');
            $err = self::thrown(fn () => new Client(null, ['local' => ['mode' => Mode::LOCAL, 'locationId' => 'l', 'stationUrls' => [$st->url], 'fingerprints' => $pins]]));
            $this->assertInstanceOf(\InvalidArgumentException::class, $err, 'local mode without a key or token');

            $c = new Client(null, ['local' => ['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => $pins, 'token' => 'czd_paired', 'discover' => true]]);
            $job = $c->jobs->create(['role' => 'kitchen', 'text' => 'x']);
            $this->assertSame(Job::VIA_LOCAL, $job->via);
            $this->assertTrue($job->live, 'paired devices are live');
            $this->assertSame('st1', $c->local->status()->stationId);
            $this->assertSame('czd_paired', $c->local->token());
            $c->local->refresh(); // no cloud: nothing to do
            $e = self::thrown(fn () => $c->printers->list());
            $this->assertInstanceOf(AuthenticationException::class, $e);
            $this->assertSame('missing_api_key', $e->errorCode);
            $e = self::thrown(fn () => $c->localTokens->create(['deviceName' => 'x']));
            $this->assertSame('missing_api_key', $e instanceof CatzPrintException ? $e->errorCode : null);
            $this->assertNull($c->__debugInfo()['apiKey']);
        } finally {
            if ($prev !== false) {
                putenv("CATZPRINT_API_KEY=$prev");
            }
        }
    }

    public function testMintFailureWith4xxIsReturnedNotFallenBack(): void
    {
        self::cloud()->configure(['mintStatus' => 403, 'mintCode' => 'missing_scope']);
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(PermissionException::class, $err);
        $this->assertCount(0, self::cloud()->jobs());

        // Cloud unreachable while minting: "station unreachable" (and local_first's cloud fails too).
        $c2 = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]], 'down');
        $err = self::thrown(fn () => $c2->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertSame('no_local_token', $err->attempts[0]->error instanceof CatzPrintException ? $err->attempts[0]->error->errorCode : null);
        $this->assertCount(0, $st->requests());
    }
}

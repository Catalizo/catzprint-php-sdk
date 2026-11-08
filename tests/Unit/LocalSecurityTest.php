<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Exception\CatzPrintException;
use CatzPrint\Exception\ConnectionException;
use CatzPrint\Exception\PinMismatchException;
use CatzPrint\Exception\StationUnreachableException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Internal\Local\LocalState;
use CatzPrint\Internal\Local\Mdns;
use CatzPrint\Internal\Local\MdnsStation;
use CatzPrint\Internal\Local\Net;
use CatzPrint\Internal\Local\StationTransport;
use CatzPrint\Local\Mode;
use CatzPrint\Local\StoredToken;
use CatzPrint\Model\Job;
use CatzPrint\Model\LocalEndpoint;
use CatzPrint\Tests\Support\LocalHelpers;
use CatzPrint\Tests\TestCase;

/**
 * Trust rules for stations (spec §5, addendum A10–A12, A15): certificate
 * pinning before any request byte, SPKI pinning of public hostnames,
 * private-address filtering, mDNS never trusted on its own, pairing needs a
 * fingerprint, and LocalJob.live.
 */
final class LocalSecurityTest extends TestCase
{
    use LocalHelpers;

    /** sha256 of tests/fixtures/local/station.crt (DER), from `openssl x509 -fingerprint -sha256`. */
    private const STATION_FP = '36b5ad91a4878d13da10547f5cdeea65688bf7464dae10f535d339f9efa1d138';
    /** sha256 of public.crt's SubjectPublicKeyInfo, from `openssl x509 -pubkey | openssl pkey -pubin -outform DER | sha256sum`. */
    private const PUBLIC_SPKI = '855cfdc118ebc2cd1503f53511a1f126694b50385575ad0602e2d602b3e12288';
    private const PUBLIC_HOST = '127-0-0-1.abcdefghij.local.catzprint.com';

    public function testFixtureHashesMatchOpenSsl(): void
    {
        $st = $this->station();
        $this->assertSame(self::STATION_FP, $st->fp);
        $this->assertSame(self::PUBLIC_SPKI, StationTransport::spkiSha256((string) file_get_contents(self::fixture('public.crt'))));
    }

    public function testNoFingerprintNeverContacted(): void
    {
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertSame('no_pinned_fingerprint', $err->attempts[0]->error instanceof CatzPrintException ? $err->attempts[0]->error->errorCode : null);
        $this->assertCount(0, $st->connections(), 'not even a TCP connection');
    }

    public function testPinMismatchRejectedBeforeAnyRequestByte(): void
    {
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => str_repeat('ab', 32)]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'secret order']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $pm = $err->attempts[0]->error;
        $this->assertInstanceOf(PinMismatchException::class, $pm);
        $this->assertSame($st->fp, $pm->got);
        $this->assertSame(str_repeat('ab', 32), $pm->want);
        $this->assertFalse($pm->spki);
        usleep(100_000);
        $conns = $st->connections();
        $this->assertGreaterThanOrEqual(1, count($conns), 'the TLS handshake happened');
        foreach ($conns as $conn) {
            $this->assertTrue($conn['tls']);
            $this->assertSame(0, $conn['bytes'], 'no request byte reached the station');
        }
        $this->assertCount(0, $st->requests());

        // local_first: a pin mismatch is "unreachable" -> the cloud.
        $lf = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => str_repeat('ab', 32)]]);
        $this->assertSame(Job::VIA_CLOUD, $lf->jobs->create(['text' => 'x'])->via);
    }

    public function testFingerprintWithColonsUpperCaseAndHostKey(): void
    {
        $st = $this->station();
        $pretty = implode(':', str_split(strtoupper($st->fp), 2));
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => ['127.0.0.1:' . $st->port => $pretty]]);
        $this->assertSame(Job::VIA_LOCAL, $c->jobs->create(['text' => 'x'])->via);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => ['127.0.0.1' => $st->fp]]);
        $this->assertSame(Job::VIA_LOCAL, $c->jobs->create(['text' => 'x'])->via);
    }

    public function testFingerprintFromTheCloudEndpointList(): void
    {
        $st = $this->station();
        self::cloud()->configure(['stations' => [['station_id' => 'st1', 'local_enabled' => true, 'urls' => [$st->url], 'tls_mode' => 'self_signed', 'cert_fingerprint_sha256' => $st->fp]]]);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url]]);
        $job = $c->jobs->create(['text' => 'x']);
        $this->assertSame('st1', $job->stationId);

        // A list with another fingerprint for that URL: refused.
        self::cloud()->reset();
        self::cloud()->configure(['stations' => [['station_id' => 'st1', 'local_enabled' => true, 'urls' => [$st->url], 'tls_mode' => 'self_signed', 'cert_fingerprint_sha256' => str_repeat('cd', 32)]]]);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(PinMismatchException::class, $err instanceof StationUnreachableException ? $err->attempts[0]->error : null);
    }

    /** @return array<string, mixed> */
    private static function publicEndpoint(int $port, string $spki, string $label = 'abcdefghij'): array
    {
        return ['station_id' => 'st1', 'local_enabled' => true, 'tls_mode' => 'public', 'local_label' => $label, 'port' => $port,
            'urls' => ['https://' . self::PUBLIC_HOST . ':' . $port], 'public_spki_sha256' => $spki,
            'cert_fingerprint_sha256' => str_repeat('00', 32)];
    }

    public function testPublicHostnameVerifiedSpkiPinnedAndDialledUnderItsLabel(): void
    {
        $st = $this->station('public');
        $hostUrl = 'https://' . self::PUBLIC_HOST . ':' . $st->port;
        $ca = self::fixture('ca.crt');

        // Verified with the CA, SPKI matches, dialled at 127.0.0.1 (no DNS).
        self::cloud()->configure(['stations' => [self::publicEndpoint($st->port, self::PUBLIC_SPKI)]]);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'discover' => true, 'caFile' => $ca]);
        $job = $c->jobs->create(['text' => 'x']);
        $this->assertSame($hostUrl, $job->local?->stationUrl);
        $this->assertSame(self::PUBLIC_HOST . ':' . $st->port, $st->requests()[0]['host']);

        // Same chain, but the cloud reports another public key: refused
        // even though the CA check passes, before any request byte.
        $before = count($st->requests());
        self::cloud()->reset();
        self::cloud()->configure(['stations' => [self::publicEndpoint($st->port, str_repeat('ab', 32))]]);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'discover' => true, 'caFile' => $ca]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $pm = $err->attempts[0]->error;
        $this->assertInstanceOf(PinMismatchException::class, $pm);
        $this->assertTrue($pm->spki);
        $this->assertSame(self::PUBLIC_SPKI, $pm->got);
        $this->assertCount($before, $st->requests());
        usleep(100_000);
        $conns = $st->connections();
        $this->assertSame(0, $conns[count($conns) - 1]['bytes']);

        // Without the CA (default roots) the same certificate is refused.
        self::cloud()->reset();
        self::cloud()->configure(['stations' => [self::publicEndpoint($st->port, self::PUBLIC_SPKI)]]);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'discover' => true]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertSame('tls_error', $err->attempts[0]->error instanceof CatzPrintException ? $err->attempts[0]->error->errorCode : null);
        $this->assertCount($before, $st->requests());

        // A label that isn't the hostname's: not dialled at the dashed IP
        // (DNS only, which doesn't resolve here).
        self::cloud()->reset();
        self::cloud()->configure(['stations' => [self::publicEndpoint($st->port, self::PUBLIC_SPKI, 'otherlabel')]]);
        $conns = count($st->connections());
        $c = $this->localClient(['mode' => Mode::LOCAL, 'discover' => true, 'caFile' => $ca]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertCount($conns, $st->connections());

        // A configured hostname the cloud doesn't know isn't dialled at the
        // IP in its name either.
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$hostUrl], 'caFile' => $ca, 'token' => 'czd_x'], 'down');
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertCount($conns, $st->connections());
    }

    public function testPrivateAddressFiltering(): void
    {
        $fp = str_repeat('cd', 32);
        $ts = LocalState::endpointTargets(new LocalEndpoint([
            'station_id' => 's', 'tls_mode' => 'public', 'local_label' => 'abcdefghij', 'cert_fingerprint_sha256' => $fp,
            'public_spki_sha256' => str_repeat('ef', 32), 'urls' => [
                'https://192-168-1-20.abcdefghij.local.catzprint.com:7443', 'https://192.168.1.20:7443', 'https://203.0.113.9:7443',
                'https://[fd00::1]:7443', 'https://100.64.1.1:7443', 'https://[2001:db8::1]:7443', 'http://192.168.1.21:7443',
            ],
        ]), 'cloud');
        $this->assertSame(
            ['https://192-168-1-20.abcdefghij.local.catzprint.com:7443', 'https://192.168.1.20:7443', 'https://[fd00::1]:7443'],
            array_map(static fn ($t) => $t->url, $ts),
        );
        $this->assertSame('abcdefghij', $ts[0]->label);
        $this->assertSame(str_repeat('ef', 32), $ts[0]->spki);
        $this->assertFalse($ts[0]->needsPin());
        $this->assertSame($fp, $ts[1]->fingerprint);
        $this->assertTrue($ts[1]->needsPin());

        foreach ([
            '10.1.2.3' => true, '172.16.0.1' => true, '172.31.255.255' => true, '172.32.0.1' => false, '192.168.0.1' => true,
            '127.0.0.1' => true, '169.254.1.1' => true, '100.64.1.1' => false, '8.8.8.8' => false, '203.0.113.9' => false,
            'fd12::1' => true, 'fc00::1' => true, 'fe80::1' => true, '::1' => true, '2001:db8::1' => false,
            '::ffff:192.168.1.1' => true, '::ffff:8.8.8.8' => false, 'example.com' => false, '' => false,
        ] as $ip => $want) {
            $this->assertSame($want, Net::privateIp((string) $ip), "privateIp($ip)");
        }
        foreach ([
            ['192-168-1-20.ab3k9x2mqp.local.catzprint.com', 'ab3k9x2mqp', '192.168.1.20'],
            ['192-168-1-20.ab3k9x2mqp.local.catzprint.com', 'otherlabel', null],
            ['192-168-1-20.ab3k9x2mqp.local.catzprint.com', '', null],
            ['10-0-0-5.x.y', 'x', '10.0.0.5'],
            ['8-8-8-8.x.local.catzprint.com', 'x', null],
            ['169-254-1-1.x.local.catzprint.com', 'x', null],
            ['192-168-01-20.x.y', 'x', null],
            ['192-168-1.x.y', 'x', null],
            ['192-168-1-20.com', 'com', null],
            ['printer.example.com', 'example', null],
        ] as [$host, $label, $want]) {
            $this->assertSame($want, Net::dashedIp($host, $label), "dashedIp($host, $label)");
        }
    }

    public function testMdnsImpostorIsNeverTrusted(): void
    {
        $realFp = str_repeat('aa', 32);
        $evilFp = str_repeat('bb', 32);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'discover' => true]);
        $l = $c->localState();
        $st = new StoredToken();
        $st->stationsUpdatedAt = new \DateTimeImmutable();
        $st->stations = [new LocalEndpoint(['station_id' => 'real-station', 'local_enabled' => true, 'tls_mode' => 'public', 'port' => 7443,
            'cert_fingerprint_sha256' => $realFp, 'addresses' => ['192.168.1.20']])];
        // An impostor copying a real station's ID: pinned to the cloud's fingerprint, never its own.
        $evil = new MdnsStation('Evil', 'real-station', '', '', 7443, $evilFp, 'public', ['192.168.1.66']);
        $acc = $l->acceptedMdns([$evil], $st);
        $this->assertCount(1, $acc);
        $targets = LocalState::mdnsTargets($acc[0]);
        $this->assertCount(1, $targets);
        $this->assertSame('https://192.168.1.66:7443', $targets[0]->url);
        $this->assertTrue($targets[0]->needsPin());
        $this->assertSame($realFp, $targets[0]->fingerprint);
        // No endpoint list (paired device): the announced fingerprint alone is not trusted.
        $this->assertCount(0, $l->acceptedMdns([new MdnsStation('x', 'x', '', '', 7443, $evilFp, 'self_signed', ['192.168.1.66'])], null));
        // Public addresses announced over mDNS are dropped.
        $this->assertCount(0, $l->acceptedMdns([new MdnsStation('Evil', 'real-station', '', '', 7443, $evilFp, 'public', ['203.0.113.9'])], $st));
        // Another org's announcement is ignored.
        $this->assertCount(0, $l->acceptedMdns([new MdnsStation('Evil', 'real-station', 'org2', '', 7443, $evilFp, 'public', ['192.168.1.66'])], $st));
        // A station disabled on the cloud list is ignored.
        $st->stations = [new LocalEndpoint(['station_id' => 'real-station', 'local_enabled' => false, 'cert_fingerprint_sha256' => $realFp])];
        $this->assertCount(0, $l->acceptedMdns([$evil], $st));
        // A fingerprint the user pinned is trusted without the list.
        $pinned = $this->localClient(['mode' => Mode::LOCAL, 'discover' => true, 'fingerprints' => ['192.168.1.66' => $evilFp]]);
        $this->assertCount(1, $pinned->localState()->acceptedMdns([new MdnsStation('x', 'x', '', '', 7443, $evilFp, 'self_signed', ['192.168.1.66'])], null));
    }

    public function testMdnsBrowseMapsListedStationToItsAddress(): void
    {
        if (!Mdns::available()) {
            self::markTestSkipped('ext-sockets is not loaded');
        }
        $st = $this->station();
        $dir = self::tempDir();
        $proc = null;
        try {
            $name = static fn (string $n): string => Mdns::encodeName($n);
            $rr = static fn (string $n, int $type, string $rdata): string => $name($n) . pack('nnNn', $type, 0x8001, 120, strlen($rdata)) . $rdata;
            $txt = static fn (array $kv): string => implode('', array_map(static fn (string $s): string => chr(strlen($s)) . $s, $kv));
            $inst = 'Kitchen PC (CatzPrint)._catzprint._tcp.local.';
            $evil = 'Evil (CatzPrint)._catzprint._tcp.local.';
            $answer = pack('n6', 0, 0x8400, 0, 4, 0, 4)
                . $rr('_catzprint._tcp.local.', 12, $name($inst))
                . $rr('_catzprint._tcp.local.', 12, $name($evil))
                . $rr($inst, 16, $txt(['v=1', 'id=st1', 'org=org1', 'loc=loc1', 'port=' . $st->port, 'fp=' . str_repeat('cc', 32), 'tls=public', 'host=192-168-1-66.attackerlbl.local.catzprint.com']))
                . $rr($evil, 16, $txt(['v=1', 'id=st-evil', 'org=org1', 'port=' . $st->port, 'fp=' . str_repeat('dd', 32), 'tls=self_signed']))
                . $rr($inst, 33, pack('nnn', 0, 0, 7443) . $name('catzprint-st1.local.'))
                . $rr($evil, 33, pack('nnn', 0, 0, $st->port) . $name('evil.local.'))
                . $rr('catzprint-st1.local.', 1, (string) inet_pton('127.0.0.1'))
                . $rr('evil.local.', 1, (string) inet_pton('127.0.0.1'));
            file_put_contents("$dir/answer", $answer);
            $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/fake_mdns.php', $dir], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            for ($i = 0; $i < 200 && !is_file("$dir/port"); $i++) {
                usleep(10_000);
            }
            $port = (int) file_get_contents("$dir/port");

            // The parser alone.
            $col = new Mdns();
            $col->add($answer, '127.0.0.1');
            $parsed = $col->stations();
            $this->assertCount(2, $parsed);
            $this->assertSame('Evil (CatzPrint)', $parsed[0]->instance);
            $this->assertSame('st1', $parsed[1]->stationId);
            $this->assertSame($st->port, $parsed[1]->port, 'TXT port wins over SRV');
            $this->assertSame(['127.0.0.1'], $parsed[1]->addresses);
            $col->add(substr($answer, 0, 40), '127.0.0.1'); // truncated: ignored
            $col->add("\xc0\x0c" . str_repeat("\xc0\x0c", 10), '127.0.0.1');

            // st1 is on the cloud list (no URLs, so only mDNS can find it);
            // st-evil isn't and its own fingerprint isn't pinned.
            self::cloud()->configure(['stations' => [['station_id' => 'st1', 'name' => 'Kitchen PC', 'location_id' => 'loc1', 'local_enabled' => true,
                'port' => $st->port, 'urls' => [], 'addresses' => [], 'tls_mode' => 'self_signed', 'cert_fingerprint_sha256' => $st->fp]]]);
            $c = $this->localClient(['mode' => Mode::LOCAL, 'locationId' => 'loc1', 'discover' => true, 'discoverTimeout' => 0.5]);
            $c->localState()->setMdnsAddress("127.0.0.1:$port");
            $job = $c->jobs->create(['text' => 'x']);
            $this->assertSame('https://127.0.0.1:' . $st->port, $job->local?->stationUrl);
            $this->assertSame('st1', $job->stationId);
            $this->assertGreaterThanOrEqual(1, (int) @file_get_contents("$dir/queries"));

            $found = $c->local->discover();
            $this->assertCount(1, $found, 'the unlisted station is ignored');
            $this->assertSame('st1', $found[0]->stationId);
            $this->assertTrue($found[0]->fromCloud);
            $this->assertTrue($found[0]->fromMdns);
            $this->assertSame($st->fp, $found[0]->fingerprint, 'the cloud fingerprint, never the announced one');
            $this->assertSame(['https://127.0.0.1:' . $st->port], $found[0]->urls);
            foreach ($found[0]->urls as $u) {
                $this->assertStringNotContainsString('attackerlbl', $u);
            }
        } finally {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
            self::rmTree($dir);
        }
    }

    public function testPairRequiresTrust(): void
    {
        $st = $this->station();
        $c = $this->localClient([], 'down'); // cloud mode, no cloud

        $err = self::thrown(fn () => $c->local->pair($st->url, '12345678', 'Till'));
        $this->assertInstanceOf(ValidationException::class, $err);
        $this->assertSame('invalid', $err->errorCode);
        $this->assertArrayHasKey('fingerprint', $err->fields);
        $this->assertCount(0, $st->connections());

        $err = self::thrown(fn () => $c->local->pair($st->url, '1234', 'Till', $st->fp));
        $this->assertArrayHasKey('code', $err instanceof ValidationException ? $err->fields : []);

        // A relay (wrong fingerprint) is detected before the code is sent.
        $err = self::thrown(fn () => $c->local->pair($st->url, '12345678', 'Till', str_repeat('ab', 32)));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertInstanceOf(PinMismatchException::class, $err->attempts[0]->error);
        $this->assertCount(0, $st->requests());
        usleep(100_000);
        foreach ($st->connections() as $conn) {
            $this->assertSame(0, $conn['bytes']);
        }

        $host = 'https://127-0-0-1.abcdefghij.local.catzprint.com:' . $st->port;
        $err = self::thrown(fn () => $c->local->pair($host, '12345678', 'Till'));
        $this->assertSame('invalid', $err instanceof ValidationException ? $err->errorCode : null, 'unknown hostname');
        $err = self::thrown(fn () => $c->local->pair($host, '12345678', 'Till', $st->fp));
        $this->assertSame('invalid', $err instanceof ValidationException ? $err->errorCode : null, 'fingerprint needs an IP URL');

        $p = $c->local->pair($st->url, '12345678', 'Till', implode(':', str_split(strtoupper($st->fp), 2)));
        $this->assertSame('czd_paired', $p->token);
        $this->assertSame('d_0123456789abcdef', $p->deviceId);
        $pair = $st->requests()[0];
        $this->assertSame(['code' => '12345678', 'device_name' => 'Till'], $pair['body']);
        $this->assertSame('', $pair['auth']);

        // A station on the cloud endpoint list can be paired without a fingerprint.
        self::cloud()->configure(['stations' => [['station_id' => 'st1', 'local_enabled' => true, 'urls' => [$st->url], 'tls_mode' => 'self_signed', 'cert_fingerprint_sha256' => $st->fp]]]);
        $c2 = $this->localClient([]);
        $c2->local->refresh();
        $this->assertSame('czd_paired', $c2->local->pair($st->url, '12345678')->token);
        $this->assertSame('Hub', $st->requests()[1]['body']['device_name']);
        $err = self::thrown(fn () => $c2->local->pair($st->url, '87654321'));
        $this->assertSame('invalid_code', $err instanceof CatzPrintException ? $err->errorCode : null);
    }

    public function testLocalJobLive(): void
    {
        $st = $this->station();
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp], 'token' => 'czd_paired'], 'down');
        $this->assertTrue($c->jobs->create(['text' => 'x'])->live, 'paired devices are live');
        $st->configure(['live' => false]);
        $j = $c->jobs->create(['text' => 'y']);
        $this->assertFalse($j->live, "the station's answer wins");
        $this->assertFalse($j->local?->live);
        // A test-mode czl1 token is not live.
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $testTok = 'czl1.' . $b64((string) json_encode(['jti' => 't', 'org' => 'org1', 'live' => false])) . '.c2ln';
        $st->configure(['live' => null]);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp], 'token' => $testTok], 'down');
        $this->assertFalse($c->jobs->create(['text' => 'z'])->live);
    }

    public function testRedirectIsNotFollowed(): void
    {
        // A 3xx after the request was written is "delivered, no answer": the
        // station is asked by key (404 here: never recorded), then the cloud.
        $st = $this->station();
        $st->configure(['fail' => ['POST /local/v1/jobs' => [307, 'moved']]]);
        $c = $this->localClient(['mode' => Mode::LOCAL_FIRST, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]]);
        $this->assertSame(Job::VIA_CLOUD, $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'k-3xx'])->via);
        $reqs = $st->requests();
        $this->assertCount(2, $reqs);
        $this->assertSame('GET', $reqs[1]['method']);
        $this->assertSame('/local/v1/jobs', $reqs[1]['path']);
        $this->assertSame('idempotency_key=k-3xx', $reqs[1]['query']);
    }

    public function testConnectionErrorsAreConnectionExceptions(): void
    {
        $dead = self::deadUrl();
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$dead], 'fingerprints' => [$dead => str_repeat('ab', 32)]]);
        $err = self::thrown(fn () => $c->jobs->create(['text' => 'x']));
        $this->assertInstanceOf(StationUnreachableException::class, $err);
        $this->assertInstanceOf(ConnectionException::class, $err->attempts[0]->error);
        $this->assertStringContainsString($dead, $err->getMessage());
    }
}

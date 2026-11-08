<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Local\FileTokenStore;
use CatzPrint\Local\MemoryTokenStore;
use CatzPrint\Local\Mode;
use CatzPrint\Local\StoredToken;
use CatzPrint\Local\TokenStore;
use CatzPrint\Model\Job;
use CatzPrint\Model\LocalEndpoint;
use CatzPrint\Tests\Support\LocalHelpers;
use CatzPrint\Tests\TestCase;

/**
 * Token stores (FileTokenStore, MemoryTokenStore), the store key, tokens
 * surviving "restarts" (new PHP requests), and the lazy refresh rules.
 */
final class TokenStoreTest extends TestCase
{
    use LocalHelpers;

    private static function token(string $tok = 'czl1.a.b'): StoredToken
    {
        $t = new StoredToken();
        $t->token = $tok;
        $t->tokenId = 'jti-1';
        $t->expiresAt = new \DateTimeImmutable('2030-01-02T03:04:05Z');
        $t->stations = [new LocalEndpoint(['station_id' => 'st1', 'urls' => ['https://192.168.1.20:7443'], 'cert_fingerprint_sha256' => str_repeat('ab', 32)])];
        $t->stationsUpdatedAt = new \DateTimeImmutable('2026-11-07T10:00:00Z');

        return $t;
    }

    public function testFileTokenStore(): void
    {
        $dir = self::tempDir();
        try {
            $path = "$dir/sub/catzprint-local.json";
            $s = new FileTokenStore($path);
            $this->assertSame($path, $s->path());
            $this->assertNull($s->load('k1'), 'nothing stored yet');
            $s->save('k1', self::token());
            $s->save('k2', self::token('czl1.other.x'));
            $this->assertSame(0o600, fileperms($path) & 0o777);
            $this->assertSame(0o700, fileperms("$dir/sub") & 0o777);
            $this->assertSame(0o600, fileperms("$path.lock") & 0o777);
            $this->assertSame(['.', '..', 'catzprint-local.json', 'catzprint-local.json.lock'], scandir("$dir/sub"), 'no temporary files left');

            $got = (new FileTokenStore($path))->load('k1');
            $this->assertNotNull($got);
            $this->assertSame('czl1.a.b', $got->token);
            $this->assertSame('jti-1', $got->tokenId);
            $this->assertSame('2030-01-02T03:04:05+00:00', $got->expiresAt?->format(DATE_ATOM));
            $this->assertSame('st1', $got->stations[0]->stationId);
            $this->assertSame('czl1.other.x', $s->load('k2')?->token);
            $json = json_decode((string) file_get_contents($path), true);
            $this->assertSame(['k1', 'k2'], array_keys($json));
            $this->assertSame('jti-1', $json['k1']['token_id'], 'same field names as the Go SDK');
            $this->assertArrayHasKey('stations_updated_at', $json['k1']);

            $s->save('k1', null);
            $this->assertNull($s->load('k1'));
            $this->assertSame('czl1.other.x', $s->load('k2')?->token);

            // update() is a locked read-modify-write.
            $res = $s->update('k2', static function (?StoredToken $t): ?StoredToken {
                $t ??= new StoredToken();
                $t->lastRemintAt = new \DateTimeImmutable('2026-11-07T11:00:00Z');

                return $t;
            });
            $this->assertSame('2026-11-07T11:00:00+00:00', $res?->lastRemintAt?->format(DATE_ATOM));
            $this->assertSame('czl1.other.x', $s->load('k2')?->token);
            $this->assertSame('czl1.other.x', $s->update('k2', static fn (): ?StoredToken => null)?->token, 'null leaves it unchanged');

            // A corrupt file: load reports it, save replaces it.
            file_put_contents($path, '{not json');
            $this->assertNotNull(self::thrown(fn () => $s->load('k2')));
            $s->save('k3', self::token());
            $this->assertSame('czl1.a.b', $s->load('k3')?->token);
            $this->assertNull($s->load('k2'));

            // Go's zero times read as "unset".
            file_put_contents($path, json_encode(['g' => ['token' => 'czl1.g.x', 'expires_at' => '2030-01-01T00:00:00.123456789Z', 'last_remint_at' => '0001-01-01T00:00:00Z']]));
            $g = $s->load('g');
            $this->assertNull($g?->lastRemintAt);
            $this->assertSame('2030', $g?->expiresAt?->format('Y'));

            $this->assertNotNull(self::thrown(fn () => new FileTokenStore('php://memory')));
        } finally {
            self::rmTree($dir);
        }
    }

    public function testMemoryTokenStore(): void
    {
        $s = new MemoryTokenStore();
        $this->assertNull($s->load('k'));
        $t = self::token();
        $s->save('k', $t);
        $t->token = 'changed';
        $this->assertSame('czl1.a.b', $s->load('k')?->token, 'stored by value');
        $s->update('k', static function (?StoredToken $t): StoredToken {
            $t ??= new StoredToken();
            $t->tokenId = 'jti-9';

            return $t;
        });
        $this->assertSame('jti-9', $s->load('k')?->tokenId);
        $s->save('k', null);
        $this->assertNull($s->load('k'));
    }

    public function testStoreKeyNeverContainsTheApiKey(): void
    {
        $a = $this->localClient(['mode' => Mode::LOCAL, 'locationId' => 'loc1', 'discover' => true]);
        $b = $this->localClient(['mode' => Mode::LOCAL, 'locationId' => 'loc2', 'discover' => true]);
        $c = $this->localClient(['mode' => Mode::LOCAL, 'locationId' => 'loc1', 'discover' => true, 'deviceName' => 'Till 2']);
        $d = $this->localClient(['mode' => Mode::LOCAL, 'locationId' => 'loc1', 'discover' => true], null, [], 'cz_test_otherKEY000000000000000000');
        $k = $a->localState()->storeKey;
        $this->assertSame('v1|' . substr(hash('sha256', 'cz_test_localTESTsecretKEY0123456789'), 0, 16) . '|loc1|Hub', $k);
        $this->assertStringNotContainsString('localTESTsecretKEY', $k);
        $this->assertCount(4, array_unique([$k, $b->localState()->storeKey, $c->localState()->storeKey, $d->localState()->storeKey]));
    }

    public function testTokenSurvivesRestartWhileTheCloudIsDown(): void
    {
        $st = $this->station();
        $dir = self::tempDir();
        try {
            $store = new FileTokenStore("$dir/t.json");
            $cfg = ['mode' => Mode::LOCAL_FIRST, 'locationId' => 'loc1', 'discover' => true, 'tokenStore' => $store];
            self::cloud()->configure(['stations' => [['station_id' => 'st1', 'location_id' => 'loc1', 'local_enabled' => true, 'port' => $st->port,
                'urls' => [$st->url], 'tls_mode' => 'self_signed', 'cert_fingerprint_sha256' => $st->fp]]]);
            // Request 1 (online): mints, gets the endpoint list with the token.
            $this->assertSame(Job::VIA_LOCAL, $this->localClient($cfg)->jobs->create(['text' => 'x'])->via);
            $this->assertCount(1, self::cloud()->mints());
            $this->assertSame(0, self::cloud()->endpointCalls(), 'the list came with the token');

            // Request 2 (a new PHP process, internet down): the stored token and
            // endpoint list (with the pinned fingerprint) are enough.
            $offline = $this->localClient($cfg, 'down');
            $job = $offline->jobs->create(['text' => 'y']);
            $this->assertSame(Job::VIA_LOCAL, $job->via);
            $this->assertSame('st1', $job->stationId);
            $this->assertSame($st->jobRequests()[0]['auth'], $st->jobRequests()[1]['auth'], 'same stored token');
            $this->assertNull($offline->local->storeError());
        } finally {
            self::rmTree($dir);
        }
    }

    public function testLazyRefreshRenewsATokenWithLessThan7DaysLeft(): void
    {
        $st = $this->station();
        $store = new MemoryTokenStore();
        $cfg = ['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp], 'tokenStore' => $store];
        self::cloud()->configure(['expiresIn' => 3 * 86400, 'stations' => []]);
        $c = $this->localClient($cfg);
        $c->jobs->create(['text' => 'x']);
        // The first token has 3 days left: after the station answered, one
        // refresh minted a new one.
        $this->assertCount(2, self::cloud()->mints());
        $this->assertCount(1, $st->jobRequests(), 'the job was sent once, before the refresh');

        // Valid token, list fresh: nothing more.
        self::cloud()->configure(['expiresIn' => 30 * 86400]);
        $c->jobs->create(['text' => 'y']); // still the 3-day token from the refresh -> refresh again
        $mints = count(self::cloud()->mints());
        $c->jobs->create(['text' => 'z']);
        $this->assertCount($mints, self::cloud()->mints(), 'a 30-day token is not refreshed');
        $this->assertSame(0, self::cloud()->endpointCalls(), 'the list came with the tokens');

        // autoRefresh off: never refreshed lazily.
        $c2 = $this->localClient($cfg + ['autoRefresh' => false]);
        $c2->localState()->setClock(static fn (): float => microtime(true) + 25 * 86400);
        $c2->jobs->create(['text' => 'w']);
        $this->assertCount($mints, self::cloud()->mints());
    }

    public function testEndpointListRefreshedWhenStale(): void
    {
        $st = $this->station();
        $store = new MemoryTokenStore();
        self::cloud()->configure(['stations' => [['station_id' => 'st1', 'local_enabled' => true, 'urls' => [$st->url], 'tls_mode' => 'self_signed', 'cert_fingerprint_sha256' => $st->fp]]]);
        $cfg = ['mode' => Mode::LOCAL, 'discover' => true, 'tokenStore' => $store];
        $this->localClient($cfg)->jobs->create(['text' => 'x']);
        $this->assertSame(0, self::cloud()->endpointCalls());
        $c = $this->localClient($cfg);
        $c->localState()->setClock(static fn (): float => microtime(true) + 2 * 3600);
        $c->jobs->create(['text' => 'y']);
        $this->assertSame(1, self::cloud()->endpointCalls(), 'an hour-old list is re-read');
        $c->jobs->create(['text' => 'z']);
        $this->assertSame(1, self::cloud()->endpointCalls());
    }

    public function testFailedRefreshIsNotRetriedFor5Minutes(): void
    {
        $st = $this->station();
        $store = new MemoryTokenStore();
        self::cloud()->configure(['expiresIn' => 3 * 86400, 'stations' => []]);
        $cfg = ['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp], 'tokenStore' => $store];
        $first = $this->localClient($cfg);
        $first->localState()->setClock(static fn (): float => microtime(true) - 3600); // minted "an hour ago": refresh is due later
        $this->assertStringStartsWith('czl1.', $first->local->token());
        $this->assertCount(1, self::cloud()->mints());

        // The cloud is down now: each new request prints locally; only the
        // first tries a refresh, the next ones skip it for 5 minutes.
        $down = $this->localClient($cfg, 'down');
        $this->assertSame(Job::VIA_LOCAL, $down->jobs->create(['text' => 'x'])->via);
        $this->assertNotNull($down->local->refreshError());
        $this->assertNotNull($store->load($down->localState()->storeKey)?->refreshRetryAt);
        $down2 = $this->localClient($cfg, 'down');
        $t0 = microtime(true);
        $down2->jobs->create(['text' => 'y']);
        $this->assertNull($down2->local->refreshError(), 'no refresh attempted');
        // Six minutes later (cloud back): refreshed.
        $later = $this->localClient($cfg);
        $later->localState()->setClock(static fn (): float => microtime(true) + 360);
        $later->jobs->create(['text' => 'z']);
        $this->assertCount(2, self::cloud()->mints());
        $this->assertNull($store->load($later->localState()->storeKey)?->refreshRetryAt);
        $this->assertLessThanOrEqual(2.0, microtime(true) - $t0);
    }

    public function testStoreFailuresNeverStopPrinting(): void
    {
        $st = $this->station();
        $broken = new class implements TokenStore {
            public function load(string $key): ?StoredToken
            {
                throw new \RuntimeException('disk on fire');
            }

            public function save(string $key, ?StoredToken $token): void
            {
                throw new \RuntimeException('disk on fire');
            }
        };
        $c = $this->localClient(['mode' => Mode::LOCAL, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp], 'tokenStore' => $broken]);
        $this->assertSame(Job::VIA_LOCAL, $c->jobs->create(['text' => 'x'])->via);
        $this->assertSame(Job::VIA_LOCAL, $c->jobs->create(['text' => 'y'])->via);
        $this->assertCount(1, self::cloud()->mints(), 'the token is kept in memory');
        $this->assertSame('disk on fire', $c->local->storeError()?->getMessage());
    }
}

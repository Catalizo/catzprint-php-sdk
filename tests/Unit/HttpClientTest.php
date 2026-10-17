<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Client;
use CatzPrint\Exception\ConnectionException;
use CatzPrint\Exception\NotFoundException;
use CatzPrint\Exception\RateLimitException;
use CatzPrint\Exception\ServerException;
use CatzPrint\Exception\TimeoutException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Model\Job;
use CatzPrint\Tests\Support\FakeServer;
use CatzPrint\Tests\TestCase;

/**
 * Retries, idempotency and request shape against a local fake HTTP server.
 */
final class HttpClientTest extends TestCase
{
    private const KEY = 'cz_test_unitTESTsecretKEY0123456789';
    private const ORG = '11111111-2222-4333-8444-555555555555';

    private static ?FakeServer $server = null;
    /** @var list<float> */
    private array $sleeps = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = new FakeServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        self::server()->reset();
        $this->sleeps = [];
    }

    private static function server(): FakeServer
    {
        return self::$server ?? throw new \LogicException('server not started');
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): Client
    {
        return new Client(self::KEY, $options + [
            'baseUrl' => self::server()->url,
            'orgId' => self::ORG,
            'sleeper' => function (float $s): void {
                $this->sleeps[] = $s;
            },
            'random' => static fn (): float => 0.0,
        ]);
    }

    /** @return array<string, mixed> */
    private static function job(array $over = []): array
    {
        return $over + [
            'id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'live' => false, 'source' => 'api', 'kind' => 'escpos',
            'status' => 'queued', 'status_detail' => '', 'title' => '', 'reference' => '', 'copies' => 1,
            'printer_id' => null, 'size' => 10, 'attempts' => 0, 'billed_as' => 'test',
            'created_at' => '2026-10-16T06:03:22.474164123Z', 'scheduled_for' => null,
        ];
    }

    public function testSendsAuthUserAgentAndJson(): void
    {
        self::server()->queue([['status' => 200, 'json' => ['printers' => [['id' => 'p1', 'name' => 'Bar', 'capabilities' => ['escpos']]]]]]);
        $printers = $this->client()->printers->list();
        $this->assertCount(1, $printers);
        $this->assertSame('Bar', $printers[0]->name);
        $this->assertTrue($printers[0]->supports('escpos'));

        $req = self::server()->requests()[0];
        $this->assertSame('GET', $req['method']);
        $this->assertSame('/v1/orgs/' . self::ORG . '/printers', $req['path']);
        $this->assertSame('Bearer ' . self::KEY, $req['headers']['authorization']);
        $this->assertSame('catzprint-php/2.1.3', $req['headers']['user-agent']);
        $this->assertSame('application/json', $req['headers']['accept']);
    }

    public function testOrgIsLookedUpOnceFromKey(): void
    {
        self::server()->queue([
            ['status' => 200, 'json' => ['id' => 'k1', 'org_id' => 'org-x', 'org_name' => 'Shop', 'live' => false, 'scopes' => ['jobs:read']]],
            ['status' => 200, 'json' => ['locations' => []]],
            ['status' => 200, 'json' => ['templates' => []]],
        ]);
        $c = new Client(self::KEY, ['baseUrl' => self::server()->url]);
        $this->assertSame([], $c->locations->list());
        $this->assertSame([], $c->templates->list());
        $this->assertSame('Shop', $c->whoami()->orgName);
        $paths = array_column(self::server()->requests(), 'path');
        $this->assertSame(['/v1/key', '/v1/orgs/org-x/locations', '/v1/orgs/org-x/templates'], $paths);
    }

    public function testGetIsRetriedOn503ThenSucceeds(): void
    {
        self::server()->queue([
            ['status' => 503, 'json' => ['error' => ['code' => 'unavailable', 'message' => 'Down']]],
            ['status' => 502, 'body' => 'bad gateway'],
            ['status' => 200, 'json' => ['location' => ['id' => 'l1', 'name' => 'Main']]],
        ]);
        $loc = $this->client()->locations->get('l1');
        $this->assertSame('Main', $loc->name);
        $this->assertCount(3, self::server()->requests());
        $this->assertSame([0.25, 0.5], $this->sleeps, 'exponential backoff (jitter pinned to its minimum)');
    }

    public function testGivesUpAfterTwoRetries(): void
    {
        self::server()->queue(array_fill(0, 4, ['status' => 504, 'json' => ['error' => ['code' => 'timeout', 'message' => 'Gateway timeout']]]));
        try {
            $this->client()->locations->list();
            $this->fail('expected ServerException');
        } catch (ServerException $e) {
            $this->assertSame(504, $e->status);
        }
        $this->assertCount(3, self::server()->requests());
        $this->assertCount(2, $this->sleeps);
    }

    public function testRateLimitRespectsRetryAfter(): void
    {
        self::server()->queue([
            ['status' => 429, 'headers' => ['Retry-After' => '3'], 'json' => ['error' => ['code' => 'rate_limited', 'message' => 'Slow down']]],
            ['status' => 429, 'json' => ['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'retry_after_seconds' => 2]]],
            ['status' => 200, 'json' => ['templates' => [['id' => 't1', 'name' => 'Ticket', 'body' => [['type' => 'cut']], 'version' => 3]]]],
        ]);
        $t = $this->client()->templates->list();
        $this->assertSame(3, $t[0]->version);
        $this->assertSame([3.0, 2.0], $this->sleeps);
    }

    public function testRateLimitSurfacesAfterRetries(): void
    {
        self::server()->queue(array_fill(0, 3, ['status' => 429, 'json' => ['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'retry_after_seconds' => 1]]]));
        $this->expectException(RateLimitException::class);
        $this->client()->locations->list();
    }

    public function testClientErrorsAreNotRetried(): void
    {
        self::server()->queue([['status' => 404, 'json' => ['error' => ['code' => 'not_found', 'message' => 'Job not found.']]]]);
        try {
            $this->client()->jobs->get('nope');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('Job not found.', $e->getMessage());
        }
        $this->assertCount(1, self::server()->requests());
        $this->assertSame([], $this->sleeps);
    }

    public function testPostWithoutIdempotencyKeyIsNeverRetried(): void
    {
        self::server()->queue([
            ['status' => 503, 'json' => ['error' => ['code' => 'unavailable', 'message' => 'Down']]],
            ['status' => 200, 'json' => ['job' => self::job(['status' => 'cancelled'])]],
        ]);
        try {
            $this->client()->jobs->cancel('aaaaaaaa-0000-4000-8000-000000000001');
            $this->fail('expected ServerException');
        } catch (ServerException) {
            $this->assertCount(1, self::server()->requests());
        }
        self::server()->reset();
    }

    public function testCreateRetriesWithSameGeneratedIdempotencyKey(): void
    {
        self::server()->queue([
            ['status' => 502, 'body' => 'bad gateway'],
            ['status' => 503, 'json' => ['error' => ['code' => 'unavailable', 'message' => 'Down']]],
            ['status' => 201, 'json' => ['job' => self::job()]],
        ]);
        $job = $this->client()->printText("Hello\n", ['printerId' => 'p1', 'title' => 'Hi']);
        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame('queued', $job->status);

        $reqs = self::server()->requests();
        $this->assertCount(3, $reqs);
        $keys = [];
        foreach ($reqs as $r) {
            $this->assertSame('POST', $r['method']);
            $this->assertSame('/v1/orgs/' . self::ORG . '/jobs', $r['path']);
            $body = json_decode($r['body'], true);
            $keysSent = array_keys($body);
            sort($keysSent);
            $this->assertSame(['content', 'idempotency_key', 'kind', 'printer_id', 'title'], $keysSent);
            $this->assertSame('text', $body['kind']);
            $this->assertSame("Hello\n", $body['content'], 'text is sent as plain text, not base64');
            $keys[] = $body['idempotency_key'];
        }
        $this->assertCount(1, array_unique($keys), 'every retry reuses one key');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $keys[0]);
    }

    public function testCreateKeepsCallerIdempotencyKeyAndRetriesNetworkErrors(): void
    {
        self::server()->queue([
            ['status' => 200, 'delay' => 0.45, 'json' => ['job' => self::job()]],
            ['status' => 201, 'json' => ['job' => self::job(['reference' => 'A-1'])]],
        ]);
        $c = $this->client(['timeout' => 0.3]);
        $job = $c->printReceipt([['type' => 'text', 'text' => 'Hi'], ['type' => 'cut']], ['role' => 'receipt', 'idempotencyKey' => 'order-A-1', 'reference' => 'A-1']);
        $this->assertSame('A-1', $job->reference);
        $reqs = self::server()->requests();
        $this->assertCount(2, $reqs);
        foreach ($reqs as $r) {
            $body = json_decode($r['body'], true);
            $this->assertSame('order-A-1', $body['idempotency_key']);
            $this->assertSame([['type' => 'text', 'text' => 'Hi'], ['type' => 'cut']], $body['commands']);
            $this->assertSame('receipt', $body['role']);
        }
        $this->assertCount(1, $this->sleeps);
    }

    public function testConnectionErrorWhenServerUnreachable(): void
    {
        $c = new Client(self::KEY, ['baseUrl' => 'http://127.0.0.1:1', 'orgId' => self::ORG, 'sleeper' => function (float $s): void {
            $this->sleeps[] = $s;
        }]);
        try {
            $c->printers->list();
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame(0, $e->status);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
        $this->assertCount(2, $this->sleeps, 'GETs are retried on network errors');
    }

    public function testValidationErrorFromServer(): void
    {
        self::server()->queue([['status' => 400, 'json' => ['error' => ['code' => 'invalid', 'message' => 'Unknown printer.', 'fields' => ['printer_id' => 'Unknown printer.']]]]]);
        try {
            $this->client()->printText('x', ['printerId' => 'bad']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Unknown printer.', $e->fields['printer_id']);
        }
        $this->assertCount(1, self::server()->requests(), '400 is not retried');
    }

    public function testCreateBodyShapes(): void
    {
        $pdf = "%PDF-1.4\n%fake\n";
        self::server()->queue(array_fill(0, 4, ['status' => 201, 'json' => ['job' => self::job()]]));
        $c = $this->client();
        $c->printPdf($pdf, ['printerId' => 'p2', 'copies' => 2]);
        $c->jobs->create(['content' => "\x89PNG\r\n\x1a\nxxxx", 'locationId' => 'l1', 'options' => []]);
        $c->printTemplate('t1', ['order' => ['number' => 7]], ['role' => 'kitchen', 'scheduledFor' => new \DateTimeImmutable('2030-01-02T03:04:05+02:00')]);
        $c->jobs->create(['content' => 'a,b', 'fileName' => 'x.csv', 'kind' => 'document']);

        $bodies = array_map(static fn ($r) => json_decode($r['body'], true), self::server()->requests());
        $this->assertSame('pdf', $bodies[0]['kind']);
        $this->assertSame(base64_encode($pdf), $bodies[0]['content']);
        $this->assertSame(2, $bodies[0]['copies']);
        $this->assertSame('image', $bodies[1]['kind']);
        $this->assertSame('{}', json_encode($bodies[1]['options'] === [] ? new \stdClass() : $bodies[1]['options']));
        $this->assertStringContainsString('"options":{}', self::server()->requests()[1]['body']);
        $this->assertSame('t1', $bodies[2]['template_id']);
        $this->assertSame(['order' => ['number' => 7]], $bodies[2]['data']);
        $this->assertSame('2030-01-02T01:04:05Z', $bodies[2]['scheduled_for']);
        $this->assertArrayNotHasKey('kind', $bodies[2]);
        $this->assertSame('x.csv', $bodies[3]['file_name']);
        $this->assertSame(base64_encode('a,b'), $bodies[3]['content']);
    }

    public function testCreateRejectsBadParamsLocally(): void
    {
        $c = $this->client();
        foreach ([
            ['text' => 'a', 'commands' => []],
            ['printer' => 'typo', 'text' => 'a'],
            ['commands' => [['type' => 'cut']], 'sealed' => true],
            ['data' => ['a' => 1]],
            ['commands' => ['type' => 'cut']],
        ] as $params) {
            try {
                $c->jobs->create($params);
                $this->fail('expected InvalidArgumentException for ' . json_encode($params));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame([], self::server()->requests());
    }

    public function testListAndAutoPagination(): void
    {
        self::server()->queue([
            ['status' => 200, 'json' => ['items' => [self::job(['id' => 'j1']), self::job(['id' => 'j2'])], 'next_cursor' => 'CUR1', 'counts' => ['queued' => 2]]],
            ['status' => 200, 'json' => ['items' => [self::job(['id' => 'j3'])], 'next_cursor' => '', 'counts' => []]],
        ]);
        $c = $this->client();
        $page = $c->jobs->list(['status' => ['queued', 'sent'], 'limit' => 2, 'live' => false, 'counts' => true]);
        $this->assertCount(2, $page);
        $this->assertTrue($page->hasMore());
        $this->assertSame(['queued' => 2], $page->counts);
        $ids = [];
        foreach ($page->autoPagingIterator() as $job) {
            $ids[] = $job->id;
        }
        $this->assertSame(['j1', 'j2', 'j3'], $ids);

        $reqs = self::server()->requests();
        parse_str($reqs[0]['query'], $q0);
        parse_str($reqs[1]['query'], $q1);
        $this->assertSame(['status' => 'queued,sent', 'live' => 'false', 'limit' => '2', 'counts' => 'true'], $q0);
        $this->assertSame('CUR1', $q1['cursor']);
        $this->assertSame('queued,sent', $q1['status']);
    }

    public function testGetIncludesEventsAndToleratesUnknownFields(): void
    {
        self::server()->queue([['status' => 200, 'json' => [
            'job' => self::job(['brand_new_field' => 'hello']),
            'events' => [['at' => '2026-10-16T06:03:22Z', 'status' => 'queued', 'detail' => '', 'actor' => 'API key']],
        ]]]);
        $job = $this->client()->jobs->get('aaaaaaaa-0000-4000-8000-000000000001');
        $this->assertSame('hello', $job['brand_new_field']);
        $this->assertSame('hello', $job->brand_new_field);
        $this->assertCount(1, $job->events ?? []);
        $this->assertSame('API key', ($job->events ?? [])[0]->actor);
        $this->assertSame('2026-10-16T06:03:22.474164+00:00', $job->createdAt?->format('Y-m-d\TH:i:s.uP'));
    }

    public function testWaitForPollsUntilFinal(): void
    {
        self::server()->queue([
            ['status' => 200, 'json' => ['job' => self::job(['status' => 'queued']), 'events' => []]],
            ['status' => 200, 'json' => ['job' => self::job(['status' => 'sent']), 'events' => []]],
            ['status' => 200, 'json' => ['job' => self::job(['status' => 'printed']), 'events' => []]],
        ]);
        $job = $this->client()->jobs->waitFor('aaaaaaaa-0000-4000-8000-000000000001', 30, 0.5);
        $this->assertTrue($job->isPrinted());
        $this->assertSame([0.5, 0.5], $this->sleeps);
    }

    public function testWaitForTimesOut(): void
    {
        self::server()->queue(array_fill(0, 50, ['status' => 200, 'json' => ['job' => self::job(['status' => 'queued']), 'events' => []]]));
        $c = new Client(self::KEY, ['baseUrl' => self::server()->url, 'orgId' => self::ORG]);
        $t0 = microtime(true);
        try {
            $c->jobs->waitFor('aaaaaaaa-0000-4000-8000-000000000001', 0.3, 0.1);
            $this->fail('expected TimeoutException');
        } catch (TimeoutException $e) {
            $this->assertStringContainsString('queued', $e->getMessage());
        }
        $this->assertLessThanOrEqual(2.0, microtime(true) - $t0);
    }

    public function testReprintAndRetryBodies(): void
    {
        self::server()->queue([
            ['status' => 201, 'json' => ['job' => self::job(['reprint_of' => 'orig'])]],
            ['status' => 200, 'json' => ['job' => self::job(['status' => 'queued'])]],
        ]);
        $c = $this->client();
        $this->assertSame('orig', $c->jobs->reprint('orig', ['printerId' => 'p9', 'copies' => 3])->reprintOf);
        $c->jobs->retry('orig');
        $reqs = self::server()->requests();
        $this->assertSame('/v1/orgs/' . self::ORG . '/jobs/orig/reprint', $reqs[0]['path']);
        $this->assertSame(['printer_id' => 'p9', 'copies' => 3], json_decode($reqs[0]['body'], true));
        $this->assertSame('/v1/orgs/' . self::ORG . '/jobs/orig/retry', $reqs[1]['path']);
        $this->assertSame('{}', $reqs[1]['body']);
    }

    public function testSealedCreateRefetchesRotatedServerKey(): void
    {
        if (!function_exists('sodium_crypto_box_keypair')) {
            $this->markTestSkipped('ext-sodium not installed');
        }
        $old = sodium_crypto_box_keypair();
        $new = sodium_crypto_box_keypair();
        self::server()->queue([
            ['status' => 200, 'json' => ['public_key' => base64_encode(sodium_crypto_box_publickey($old)), 'algorithm' => 'x25519-xsalsa20-poly1305-sealed-box']],
            ['status' => 400, 'json' => ['error' => ['code' => 'invalid', 'message' => "Couldn't decrypt.", 'fields' => ['sealed_content' => "Couldn't decrypt."]]]],
            ['status' => 200, 'json' => ['public_key' => base64_encode(sodium_crypto_box_publickey($new)), 'algorithm' => 'x25519-xsalsa20-poly1305-sealed-box']],
            ['status' => 201, 'json' => ['job' => self::job(['kind' => 'pdf'])]],
        ]);
        $pdf = "%PDF-1.4\nsecret invoice\n";
        $job = $this->client()->printPdf($pdf, ['printerId' => 'p2', 'sealed' => true]);
        $this->assertSame('pdf', $job->kind);

        $reqs = self::server()->requests();
        $this->assertSame(['/v1/public/server-key', '/v1/orgs/' . self::ORG . '/jobs', '/v1/public/server-key', '/v1/orgs/' . self::ORG . '/jobs'], array_column($reqs, 'path'));
        $this->assertArrayNotHasKey('authorization', $reqs[0]['headers'], 'server key is fetched without credentials');
        $b1 = json_decode($reqs[1]['body'], true);
        $b2 = json_decode($reqs[3]['body'], true);
        $this->assertArrayNotHasKey('content', $b2);
        $this->assertSame('pdf', $b2['kind']);
        $this->assertSame($b1['idempotency_key'], $b2['idempotency_key']);
        $this->assertSame($pdf, sodium_crypto_box_seal_open((string) base64_decode($b1['sealed_content'], true), $old));
        $this->assertSame($pdf, sodium_crypto_box_seal_open((string) base64_decode($b2['sealed_content'], true), $new));
    }

    public function testRedirectIsNotFollowed(): void
    {
        self::server()->queue([['status' => 302, 'headers' => ['Location' => 'https://evil.example/steal']]]);
        $threw = false;
        try {
            $this->client()->printers->list();
        } catch (\CatzPrint\Exception\CatzPrintException $e) {
            $threw = true;
            $this->assertSame(302, $e->status);
        }
        $this->assertTrue($threw);
        $this->assertCount(1, self::server()->requests());
    }

    public function testWaitForZeroIntervalDoesNotSpin(): void
    {
        self::server()->queue([
            ['status' => 200, 'json' => ['job' => self::job(['status' => 'queued'])]],
            ['status' => 200, 'json' => ['job' => self::job(['status' => 'printed'])]],
        ]);
        $job = $this->client()->jobs->waitFor('j1', 30.0, 0.0);
        $this->assertSame('printed', $job->status);
        $this->assertSame([1.0], $this->sleeps);
    }
}

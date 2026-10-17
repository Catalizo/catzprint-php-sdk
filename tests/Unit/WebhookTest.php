<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Client;
use CatzPrint\Exception\SignatureVerificationException;
use CatzPrint\Model\Event;
use CatzPrint\Webhook;
use CatzPrint\Tests\TestCase;

/**
 * Vectors in tests/fixtures/webhook_vectors.json were produced by a verbatim
 * copy of SignWebhook from server/internal/core/webhooks.go
 * (tests/fixtures/gen_webhook_vectors.go).
 */
final class WebhookTest extends TestCase
{
    /** @return list<array{name: string, secret: string, timestamp: int, body: string, header: string}> */
    private static function vectors(): array
    {
        $raw = file_get_contents(dirname(__DIR__) . '/fixtures/webhook_vectors.json');

        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertRejected(callable $fn, string $code): void
    {
        try {
            $fn();
            $this->fail("expected SignatureVerificationException ($code)");
        } catch (SignatureVerificationException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    public function testGoVectorsVerify(): void
    {
        foreach (self::vectors() as $v) {
            $event = Webhook::verify($v['body'], $v['header'], $v['secret'], 300, $v['timestamp'] + 10);
            $this->assertInstanceOf(Event::class, $event);
            $decoded = json_decode($v['body'], true);
            $this->assertSame($decoded['type'], $event->type, $v['name']);
            $this->assertSame($decoded['org_id'], $event->orgId);
            $this->assertSame($decoded['data'], $event->data);
        }
    }

    public function testSignMatchesGoSignWebhook(): void
    {
        foreach (self::vectors() as $v) {
            $this->assertSame($v['header'], Webhook::sign($v['body'], $v['secret'], $v['timestamp']), $v['name']);
        }
    }

    public function testJobHelper(): void
    {
        $v = self::vectors()[0];
        $event = Webhook::constructEvent($v['body'], $v['header'], $v['secret'], 300, $v['timestamp']);
        $this->assertSame('job.printed', $event->type);
        $this->assertSame('order-1042', $event->job()?->reference);
        $this->assertTrue((bool) $event->job()?->isPrinted());
        $this->assertSame('2026-10-12T09:14:03+00:00', $event->createdAt?->format(DATE_ATOM));
    }

    public function testTamperedBodyIsRejected(): void
    {
        $v = self::vectors()[0];
        $tampered = str_replace('order-1042', 'order-1043', $v['body']);
        $this->assertRejected(fn () => Webhook::verify($tampered, $v['header'], $v['secret'], 300, $v['timestamp']), 'signature_mismatch');
        // A re-encoded (but equal) JSON body is different bytes, so it fails too.
        $reencoded = json_encode(json_decode($v['body'], true), JSON_PRETTY_PRINT);
        $this->assertRejected(fn () => Webhook::verify((string) $reencoded, $v['header'], $v['secret'], 300, $v['timestamp']), 'signature_mismatch');
        // Tampered timestamp.
        $h = preg_replace('/^t=\d+/', 't=' . ($v['timestamp'] + 1), $v['header']);
        $this->assertRejected(fn () => Webhook::verify($v['body'], (string) $h, $v['secret'], 300, $v['timestamp']), 'signature_mismatch');
    }

    public function testExpiredAndFutureTimestampsAreRejected(): void
    {
        $v = self::vectors()[0];
        $this->assertRejected(fn () => Webhook::verify($v['body'], $v['header'], $v['secret'], 300, $v['timestamp'] + 301), 'timestamp_out_of_tolerance');
        $this->assertRejected(fn () => Webhook::verify($v['body'], $v['header'], $v['secret'], 300, $v['timestamp'] - 301), 'timestamp_out_of_tolerance');
        // Exactly at the edge is still fine; tolerance 0 disables the check.
        $this->assertSame('job.printed', Webhook::verify($v['body'], $v['header'], $v['secret'], 300, $v['timestamp'] + 300)->type);
        $this->assertSame('job.printed', Webhook::verify($v['body'], $v['header'], $v['secret'], 0, $v['timestamp'] + 999999)->type);
        // Real clock: a 2026 vector is far outside 5 minutes of "now" in tests run on other dates.
        $old = self::vectors()[2];
        $this->assertRejected(fn () => Webhook::verify($old['body'], $old['header'], $old['secret']), 'timestamp_out_of_tolerance');
    }

    public function testWrongSecretIsRejected(): void
    {
        $v = self::vectors()[0];
        $this->assertRejected(fn () => Webhook::verify($v['body'], $v['header'], 'whsec_wrong', 300, $v['timestamp']), 'signature_mismatch');
        $this->assertRejected(fn () => Webhook::verify($v['body'], $v['header'], '', 300, $v['timestamp']), 'no_secret');
    }

    public function testMultipleV1EntriesAndSpacing(): void
    {
        $v = self::vectors()[1];
        [$t, $sig] = explode(',', $v['header']);
        $header = $t . ', v1=' . str_repeat('0', 64) . ', v1=' . strtoupper(substr($sig, 3)) . ',v0=ignored';
        $event = Webhook::verify($v['body'], $header, $v['secret'], 300, $v['timestamp']);
        $this->assertSame('Café € ✓', $event->data['job']['title']);
    }

    public function testMalformedHeaders(): void
    {
        $v = self::vectors()[0];
        $this->assertRejected(fn () => Webhook::verify($v['body'], null, $v['secret']), 'no_signature');
        $this->assertRejected(fn () => Webhook::verify($v['body'], '  ', $v['secret']), 'no_signature');
        $this->assertRejected(fn () => Webhook::verify($v['body'], 'v1=abc', $v['secret']), 'bad_signature_header');
        $this->assertRejected(fn () => Webhook::verify($v['body'], 't=abc,v1=abc', $v['secret']), 'bad_signature_header');
        $this->assertRejected(fn () => Webhook::verify($v['body'], 't=' . $v['timestamp'], $v['secret']), 'bad_signature_header');
        $this->assertRejected(fn () => Webhook::verify($v['body'], 'garbage', $v['secret']), 'bad_signature_header');
    }

    public function testValidSignatureButNotJson(): void
    {
        $h = Webhook::sign('not json', 's', 1000);
        $this->assertRejected(fn () => Webhook::verify('not json', $h, 's', 300, 1000), 'invalid_payload');
    }

    public function testClientWebhooksResourceAndFreshSignature(): void
    {
        $body = '{"type":"printer.status","created_at":"2026-10-16T06:00:00Z","org_id":"o","data":{"printer":{"id":"p"}}}';
        $header = Webhook::sign($body, 'whsec_live');
        $c = new Client('cz_test_abcdefghijklmnopqrstuvwxyz', ['orgId' => 'o']);
        $this->assertSame('printer.status', $c->webhooks->verify($body, $header, 'whsec_live')->type);
        $this->assertSame('o', $c->webhooks->constructEvent($body, $header, 'whsec_live')->orgId);
    }

    public function testEventId(): void
    {
        $body = '{"id":"dlv_01HZX","type":"ping","created_at":"2026-10-17T00:00:00Z","org_id":"o","data":{}}';
        $this->assertSame('dlv_01HZX', Webhook::verify($body, Webhook::sign($body, 'whsec_x'), 'whsec_x')->id);
        $old = '{"type":"ping","created_at":"2026-10-17T00:00:00Z","org_id":"o","data":{}}';
        $this->assertNull(Webhook::verify($old, Webhook::sign($old, 'whsec_x'), 'whsec_x')->id);
    }
}

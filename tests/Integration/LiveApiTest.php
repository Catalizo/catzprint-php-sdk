<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Integration;

use CatzPrint\Client;
use CatzPrint\Exception\AuthenticationException;
use CatzPrint\Exception\NotFoundException;
use CatzPrint\Exception\PermissionException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Internal\Uuid;
use CatzPrint\Model\Job;
use CatzPrint\Tests\TestCase;

/**
 * Runs against a live CatzPrint API when CATZPRINT_TEST_FIXTURE points at a
 * fixture JSON: {base_url, org_id, receipt_printer_id, office_printer_id,
 * api_key_live, api_key_test, api_key_printers_read_only, device_token,
 * device_private_key_b64}. It only creates jobs in the fixture org and plays
 * the fixture station to mark its own jobs printed.
 */
final class LiveApiTest extends TestCase
{
    /** @var array<string, string>|null */
    private static ?array $fx = null;

    public static function setUpBeforeClass(): void
    {
        $path = getenv('CATZPRINT_TEST_FIXTURE');
        if ($path === false || $path === '' || !is_file($path)) {
            self::$fx = null;

            return;
        }
        self::$fx = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function setUp(): void
    {
        if (self::$fx === null) {
            $this->markTestSkipped('set CATZPRINT_TEST_FIXTURE to run integration tests');
        }
    }

    /** @return array<string, string> */
    private static function fx(): array
    {
        return self::$fx ?? throw new \LogicException('no fixture');
    }

    private static function client(string $which = 'api_key_test'): Client
    {
        return new Client(self::fx()[$which], ['baseUrl' => self::fx()['base_url'], 'timeout' => 20]);
    }

    /**
     * Calls the station (device) API like a paired station would.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private static function device(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::fx()['base_url'] . $path);
        $headers = ['Authorization: Bearer ' . self::fx()['device_token'], 'Accept: application/json'];
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status >= 300) {
            throw new \RuntimeException("device $method $path -> $status $out");
        }

        return $out === '' ? [] : (array) json_decode($out, true);
    }

    /**
     * Plays the station: pulls pending jobs (marking them sent) and reports
     * $jobId printed. Returns the job as the station saw it, when it was in
     * this pull (another parallel run may have pulled it first).
     *
     * @return array<string, mixed>|null
     */
    private static function stationPrints(string $jobId): ?array
    {
        $seen = null;
        foreach ((array) (self::device('GET', '/v1/device/jobs')['jobs'] ?? []) as $dj) {
            if (is_array($dj) && ($dj['id'] ?? null) === $jobId) {
                $seen = $dj;
            }
        }
        self::device('POST', '/v1/device/jobs/' . $jobId . '/status', ['status' => 'printed']);

        return $seen;
    }

    public function testKeyReturnsTheOrg(): void
    {
        $test = self::client()->key();
        $this->assertSame(self::fx()['org_id'], $test->orgId);
        $this->assertFalse($test->live);
        $this->assertTrue($test->hasScope('jobs:write'));
        $this->assertNotSame('', $test->orgName);

        $live = self::client('api_key_live');
        $this->assertTrue($live->whoami()->live);
        $this->assertSame(self::fx()['org_id'], $live->orgId());
    }

    public function testReadResources(): void
    {
        $c = self::client('api_key_live');
        $ids = array_map(static fn ($p) => $p->id, $c->printers->list());
        $this->assertContains(self::fx()['receipt_printer_id'], $ids);
        $this->assertContains(self::fx()['office_printer_id'], $ids);

        $locs = $c->locations->list();
        $this->assertContains(self::fx()['location_id'], array_map(static fn ($l) => $l->id, $locs));
        $loc = $c->locations->get(self::fx()['location_id']);
        $this->assertSame(self::fx()['location_id'], $loc->id);
        $this->assertGreaterThanOrEqual(2, $loc->printers);

        $this->assertTrue(is_array($c->templates->list()));
    }

    public function testTextWithTestKeyIsUnbilled(): void
    {
        $c = self::client('api_key_test');
        $job = $c->printText("catzprint-php integration\nTest key\n", [
            'printerId' => self::fx()['receipt_printer_id'],
            'title' => 'PHP SDK text',
            'reference' => 'php-sdk-' . bin2hex(random_bytes(4)),
        ]);
        $this->assertFalse($job->live);
        $this->assertSame('test', $job->billedAs);
        $this->assertSame('api', $job->source);
        $this->assertSame('escpos', $job->kind);
        $this->assertSame(self::fx()['receipt_printer_id'], $job->printerId);

        self::stationPrints($job->id);
        $this->assertSame('printed', $c->jobs->waitFor($job->id, 30, 0.5)->status);
    }

    public function testSealedPdfWithLiveKeyPrintsAndWaits(): void
    {
        $c = self::client('api_key_live');
        $pdf = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/hello.pdf');
        $job = $c->printPdf($pdf, [
            'printerId' => self::fx()['office_printer_id'],
            'sealed' => true,
            'title' => 'PHP SDK sealed PDF',
        ]);
        $this->assertTrue($job->live);
        $this->assertSame('pdf', $job->kind);
        $this->assertNotSame('test', $job->billedAs);
        $this->assertSame(strlen($pdf), $job->size);
        $this->assertSame('queued', $job->status);

        // Play the station; if this pull carried our job, open it with the
        // station's private key and check the PDF made it through intact.
        $seen = self::stationPrints($job->id);
        if ($seen !== null && isset($seen['sealed_content']) && function_exists('sodium_crypto_box_seal_open')) {
            $sk = (string) base64_decode(self::fx()['device_private_key_b64'], true);
            $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($sk, sodium_crypto_scalarmult_base($sk));
            $opened = sodium_crypto_box_seal_open((string) base64_decode((string) $seen['sealed_content'], true), $kp);
            $this->assertSame($pdf, $opened, 'station opens exactly the PDF we sealed');
        }

        $final = $c->jobs->waitFor($job->id, 30, 0.5);
        $this->assertSame('printed', $final->status);
        $this->assertNotNull($final->printedAt);
        $this->assertContains('printed', array_map(static fn ($e) => $e->status, $final->events ?? []));

        // 5a. Listing includes it (search by ID prefix, and by printer).
        $found = $c->jobs->list(['q' => $job->id, 'limit' => 5]);
        $this->assertContains($job->id, array_map(static fn (Job $j) => $j->id, $found->items));
        $byPrinter = $c->jobs->list(['printerId' => self::fx()['office_printer_id'], 'status' => ['printed'], 'limit' => 50]);
        $this->assertContains($job->id, array_map(static fn (Job $j) => $j->id, $byPrinter->items));
    }

    public function testAutoPaginationAcrossRealPages(): void
    {
        $c = self::client('api_key_test');
        $first = $c->jobs->list(['limit' => 2]);
        if (!$first->hasMore()) {
            $this->markTestSkipped('fewer than 3 jobs in the org');
        }
        $ids = [];
        foreach ($first->autoPagingIterator() as $job) {
            $ids[] = $job->id;
            if (count($ids) === 5) {
                break;
            }
        }
        $this->assertCount(5, $ids);
        $this->assertCount(5, array_unique($ids));
    }

    public function testCancelScheduledJob(): void
    {
        $c = self::client('api_key_test');
        $job = $c->printText("Scheduled, then cancelled\n", [
            'printerId' => self::fx()['receipt_printer_id'],
            'scheduledFor' => new \DateTimeImmutable('+1 hour'),
        ]);
        $this->assertSame('scheduled', $job->status);
        $this->assertNotNull($job->scheduledFor);
        $cancelled = $c->jobs->cancel($job->id);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('cancelled', $c->jobs->waitFor($job->id, 5)->status);
    }

    public function testErrorsFromTheApi(): void
    {
        try {
            self::client('api_key_printers_read_only')->printText('nope', ['printerId' => self::fx()['receipt_printer_id']]);
            $this->fail('expected PermissionException');
        } catch (PermissionException $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('forbidden', $e->errorCode);
        }

        try {
            (new Client('cz_live_definitelyNotAValidKey000000000', ['baseUrl' => self::fx()['base_url']]))->key();
            $this->fail('expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('invalid_api_key', $e->errorCode);
            $this->assertStringNotContainsString('definitelyNotAValidKey', $e->getMessage());
        }

        $c = self::client('api_key_test');
        try {
            $c->printText('x', ['printerId' => Uuid::v4()]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(400, $e->status);
            $this->assertArrayHasKey('printer_id', $e->fields);
        }

        try {
            $c->jobs->get(Uuid::v4());
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testIdempotencyKeyReturnsTheSameJob(): void
    {
        $c = self::client('api_key_test');
        $key = 'php-sdk-idem-' . Uuid::v4();
        $params = ['printerId' => self::fx()['receipt_printer_id'], 'idempotencyKey' => $key];
        $a = $c->printText("Idempotent print\n", $params);
        $b = $c->printText("Idempotent print\n", $params);
        $this->assertSame($a->id, $b->id);
        $c->jobs->cancel($a->id);
    }
}

<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Exception\OutcomeUnknownException;
use CatzPrint\Exception\ValidationException;
use CatzPrint\Local\Mode;
use CatzPrint\Local\StoredToken;
use CatzPrint\Model\Job;
use CatzPrint\Tests\Support\LocalHelpers;
use CatzPrint\Tests\TestCase;

/**
 * SDK addendum 5 (audit round 7) regression tests for PHP: S1 (the amb400 /
 * seal400 matrix scenarios), S5 (cloud_first non-local jobs use the
 * cloud-mode create) and S6 (placeholder set, unexpected_redirect).
 */
final class Audit7Test extends TestCase
{
    use LocalHelpers;

    private const MODES = [Mode::CLOUD, Mode::LOCAL_FIRST, Mode::CLOUD_FIRST];

    /** A client in $mode with the client's retries on (maxRetries 2) and a short timeout. */
    private function client(string $mode): \CatzPrint\Client
    {
        $st = $this->station();
        $local = $mode === Mode::CLOUD ? [] : ['mode' => $mode, 'stationUrls' => [$st->url], 'fingerprints' => [$st->url => $st->fp]];

        return $this->localClient($local, null, ['maxRetries' => 2, 'timeout' => 0.5]);
    }

    private static function keyFetches(): int
    {
        return count(array_filter(self::cloud()->requests(), static fn ($r) => $r['path'] === '/v1/public/server-key'));
    }

    private function needSodium(): void
    {
        if (!function_exists('sodium_crypto_box_seal')) {
            self::markTestSkipped('ext-sodium');
        }
    }

    private function stationRequests(): int
    {
        $n = 0;
        foreach ($this->stations as $s) {
            $n += count($s->requests());
        }

        return $n;
    }

    // ---- S1 -----------------------------------------------------------------

    /** amb400: the answer was lost, then a sealed_content 400: re-sealed with the same key -> the job. */
    public function testAmb400ResealsAndResends(): void
    {
        $this->needSodium();
        foreach (self::MODES as $mode) {
            self::cloud()->reset();
            self::cloud()->configure(['jobSeq' => [['delay' => 1.0], 'seal400', 'ok']]);
            $job = $this->client($mode)->jobs->create(['text' => 'card', 'sealed' => true, 'idempotencyKey' => 'amb-1']);
            $this->assertSame(Job::VIA_CLOUD, $job->via, $mode);
            $jobs = self::cloud()->jobs();
            $this->assertCount(3, $jobs, $mode);
            $this->assertSame($jobs[0], $jobs[1], 'the retry is identical');
            $this->assertNotSame($jobs[1]['sealed_content'], $jobs[2]['sealed_content'], 're-sealed');
            $this->assertSame('amb-1', $jobs[2]['idempotency_key']);
            $this->assertSame(2, self::keyFetches(), $mode);
        }
        $this->assertSame(0, $this->stationRequests());
    }

    /** After an ambiguous attempt, a re-sealed re-send rejected again or lost: OutcomeUnknown, never Validation. */
    public function testAmb400RepeatIsOutcomeUnknown(): void
    {
        $this->needSodium();
        $lost = ['delay' => 1.0];
        foreach (self::MODES as $mode) {
            foreach ([['seal400', 'seal400'], [$lost, $lost, $lost]] as $tail) {
                self::cloud()->reset();
                self::cloud()->configure(['jobSeq' => array_merge([$lost, 'seal400'], $tail)]);
                $err = self::thrown(fn () => $this->client($mode)->jobs->create(['text' => 'card', 'sealed' => true, 'idempotencyKey' => 'amb-2']));
                $this->assertInstanceOf(OutcomeUnknownException::class, $err, $mode);
            }
        }
        $this->assertSame(0, $this->stationRequests());
    }

    /** seal400 without an earlier ambiguous attempt: re-sealed once; a repeat is the ValidationException. */
    public function testSeal400ResealsOnce(): void
    {
        $this->needSodium();
        foreach (self::MODES as $mode) {
            self::cloud()->reset();
            self::cloud()->configure(['jobSeq' => ['seal400', 'ok']]);
            $this->assertSame(Job::VIA_CLOUD, $this->client($mode)->jobs->create(['text' => 'card', 'sealed' => true])->via);
            $this->assertSame(2, self::keyFetches());
            self::cloud()->reset();
            self::cloud()->configure(['jobSeq' => ['seal400', 'seal400', 'seal400']]);
            $err = self::thrown(fn () => $this->client($mode)->jobs->create(['text' => 'card', 'sealed' => true]));
            $this->assertTrue($err instanceof ValidationException && !$err instanceof OutcomeUnknownException, $mode);
            $this->assertCount(2, self::cloud()->jobs());
        }
    }

    // ---- S5 -----------------------------------------------------------------

    /** cloud_first: a PDF / scheduled job gets the cloud-mode create (client retries, 409 loop). */
    public function testCloudFirstNonLocalUsesCloudMode(): void
    {
        foreach ([['content' => '%PDF-1.4 x'], ['text' => 'x', 'scheduledFor' => '2030-01-01T00:00:00Z']] as $p) {
            self::cloud()->reset();
            // Before: one cloudLeg attempt (no retries): the 503 ended it.
            self::cloud()->configure(['jobSeq' => [[503, 'unavailable'], [409, 'duplicate'], 'ok']]);
            $job = $this->client(Mode::CLOUD_FIRST)->jobs->create($p);
            $this->assertSame(Job::VIA_CLOUD, $job->via);
            $this->assertCount(3, self::cloud()->jobs());
        }
        $this->assertSame(0, $this->stationRequests());
    }

    // ---- S6 -----------------------------------------------------------------

    public function testPlaceholderSet(): void
    {
        foreach (['…', '***', '*****', '[redacted]', 'REDACTED', 'hidden', 'czl1.…', 'czl1.***', 'czd_[redacted]', 'czd_hidden'] as $p) {
            $this->assertTrue(StoredToken::isRedactionPlaceholder($p), $p);
        }
        foreach (['czl1.abc.def', 'czd_abc', '**', 'czl1.**', 'redacted-token', ''] as $p) {
            $this->assertFalse(StoredToken::isRedactionPlaceholder($p), $p);
        }
    }

    public function testCloudRedirectCode(): void
    {
        self::cloud()->configure(['jobSeq' => [[302, 'found']]]);
        $err = self::thrown(fn () => $this->client(Mode::CLOUD)->jobs->create(['text' => 'x']));
        $this->assertSame('unexpected_redirect', $err?->errorCode ?? null);
    }

    // ---- addendum 6, T2 ---------------------------------------------------------

    /** dip404: POST sent but not recorded, the first probe says in_progress, the next 404: OutcomeUnknown. */
    public function testProbeInProgressThen404IsUnknown(): void
    {
        foreach ([Mode::LOCAL, Mode::LOCAL_FIRST] as $mode) {
            self::cloud()->reset();
            $a = $this->station();
            $b = $this->station();
            $a->configure(['drop' => ['POST /local/v1/jobs' => 'norecord'], 'failOnce' => ['GET /local/v1/jobs' => [409, 'in_progress']]]);
            $c = $this->localClient(['mode' => $mode, 'stationUrls' => [$a->url, $b->url], 'fingerprints' => [$a->url => $a->fp, $b->url => $b->fp]]);
            $err = self::thrown(fn () => $c->jobs->create(['text' => 'x', 'idempotencyKey' => 'dip404']));
            $this->assertInstanceOf(OutcomeUnknownException::class, $err, $mode);
            $this->assertGreaterThanOrEqual(2, count(array_filter($a->requests(), static fn ($r) => $r['method'] === 'GET')));
            $this->assertCount(0, $b->requests(), 'next station');
            $this->assertCount(0, self::cloud()->jobs(), 'cloud');
        }
    }
}

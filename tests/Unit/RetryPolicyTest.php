<?php

declare(strict_types=1);

namespace CatzPrint\Tests\Unit;

use CatzPrint\Internal\RetryPolicy;
use CatzPrint\Tests\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testExponentialBackoffWithJitterBounds(): void
    {
        $low = new RetryPolicy(maxRetries: 5, random: static fn (): float => 0.0);
        $high = new RetryPolicy(maxRetries: 5, random: static fn (): float => 0.999999);
        // attempt n: cap = min(8, 0.5 * 2^n); delay in [cap/2, cap)
        foreach ([0 => 0.5, 1 => 1.0, 2 => 2.0, 3 => 4.0, 4 => 8.0] as $attempt => $cap) {
            $this->assertEqualsWithin($cap / 2, (float) $low->delay($attempt));
            $this->assertEqualsWithin($cap, (float) $high->delay($attempt), 1e-5);
        }
    }

    public function testCapAndGiveUp(): void
    {
        $p = new RetryPolicy(maxRetries: 10, random: static fn (): float => 0.999999);
        $this->assertLessThanOrEqual(8.0, $p->delay(9));
        $two = new RetryPolicy();
        $this->assertNotNull($two->delay(0));
        $this->assertNotNull($two->delay(1));
        $this->assertNull($two->delay(2), 'default policy retries at most twice');
        $this->assertNull((new RetryPolicy(maxRetries: 0))->delay(0));
    }

    public function testRetryAfterIsHonouredWithinLimit(): void
    {
        $p = new RetryPolicy(random: static fn (): float => 0.5);
        $this->assertSame(3.0, $p->delay(0, 3.0));
        $this->assertNull($p->delay(0, 120.0), 'waits longer than maxRetryAfter are not slept');
        $this->assertNull($p->delay(2, 1.0), 'still bounded by maxRetries');
    }

    public function testRetryableStatuses(): void
    {
        foreach ([429, 502, 503, 504] as $s) {
            $this->assertTrue(RetryPolicy::isRetryableStatus($s));
        }
        foreach ([400, 401, 403, 404, 409, 422, 500, 501] as $s) {
            $this->assertFalse(RetryPolicy::isRetryableStatus($s));
        }
    }

    public function testDefaultJitterVaries(): void
    {
        $p = new RetryPolicy();
        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $d = (float) $p->delay(1);
            $this->assertGreaterThanOrEqual(0.5, $d);
            $this->assertLessThanOrEqual(1.0, $d);
            $seen[(string) $d] = true;
        }
        $this->assertGreaterThan(1, count($seen));
    }

    private function assertEqualsWithin(float $expected, float $actual, float $delta = 1e-9): void
    {
        $this->assertTrue(abs($expected - $actual) <= $delta, "expected $expected, got $actual");
    }
}

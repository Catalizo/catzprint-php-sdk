<?php

declare(strict_types=1);

namespace CatzPrint\Tests;

/**
 * A tiny, dependency-free stand-in for PHPUnit\Framework\TestCase with the
 * assertions this suite uses. tests/run.php drives it.
 */
abstract class MiniTestCase
{
    /** @var class-string<\Throwable>|null */
    private ?string $expectedException = null;
    private ?string $expectedMessage = null;
    public int $assertions = 0;

    public static function setUpBeforeClass(): void
    {
    }

    public static function tearDownAfterClass(): void
    {
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    /** @internal used by tests/run.php */
    public function runTestMethod(string $method): void
    {
        $this->expectedException = null;
        $this->expectedMessage = null;
        $this->setUp();
        try {
            try {
                $this->{$method}();
            } catch (SkippedTest $e) {
                throw $e;
            } catch (AssertionFailed $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($this->expectedException === null || !($e instanceof $this->expectedException)) {
                    throw $e;
                }
                $this->assertions++;
                if ($this->expectedMessage !== null && !str_contains($e->getMessage(), $this->expectedMessage)) {
                    throw new AssertionFailed(sprintf('Exception message "%s" does not contain "%s".', $e->getMessage(), $this->expectedMessage));
                }

                return;
            }
            if ($this->expectedException !== null) {
                throw new AssertionFailed("Expected exception {$this->expectedException} was not thrown.");
            }
        } finally {
            $this->tearDown();
        }
    }

    /** @param class-string<\Throwable> $class */
    public function expectException(string $class): void
    {
        $this->expectedException = $class;
    }

    public function expectExceptionMessage(string $message): void
    {
        $this->expectedMessage = $message;
    }

    public static function markTestSkipped(string $message = ''): never
    {
        throw new SkippedTest($message);
    }

    public static function fail(string $message = ''): never
    {
        throw new AssertionFailed($message === '' ? 'Failed.' : $message);
    }

    private function check(bool $ok, string $message, string $default): void
    {
        $this->assertions++;
        if (!$ok) {
            throw new AssertionFailed($message !== '' ? $message . "\n" . $default : $default);
        }
    }

    private static function export(mixed $v): string
    {
        if (is_object($v)) {
            return get_class($v);
        }
        $s = var_export($v, true);

        return strlen($s) > 300 ? substr($s, 0, 300) . '…' : $s;
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected === $actual, $message, 'Expected ' . self::export($expected) . ', got ' . self::export($actual) . '.');
    }

    public function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected !== $actual, $message, 'Did not expect ' . self::export($actual) . '.');
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($expected == $actual, $message, 'Expected ' . self::export($expected) . ' == ' . self::export($actual) . '.');
    }

    public function assertTrue(mixed $actual, string $message = ''): void
    {
        $this->check($actual === true, $message, 'Expected true, got ' . self::export($actual) . '.');
    }

    public function assertFalse(mixed $actual, string $message = ''): void
    {
        $this->check($actual === false, $message, 'Expected false, got ' . self::export($actual) . '.');
    }

    public function assertNull(mixed $actual, string $message = ''): void
    {
        $this->check($actual === null, $message, 'Expected null, got ' . self::export($actual) . '.');
    }

    public function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->check($actual !== null, $message, 'Expected a value, got null.');
    }

    public function assertInstanceOf(string $class, mixed $actual, string $message = ''): void
    {
        $this->check($actual instanceof $class, $message, 'Expected instance of ' . $class . ', got ' . self::export($actual) . '.');
    }

    public function assertCount(int $expected, \Countable|array $haystack, string $message = ''): void
    {
        $this->check(count($haystack) === $expected, $message, "Expected count $expected, got " . count($haystack) . '.');
    }

    public function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        $found = false;
        foreach ($haystack as $v) {
            if ($v === $needle) {
                $found = true;
                break;
            }
        }
        $this->check($found, $message, self::export($needle) . ' not found.');
    }

    public function assertArrayHasKey(int|string $key, array $array, string $message = ''): void
    {
        $this->check(array_key_exists($key, $array), $message, 'Missing key ' . self::export($key) . '.');
    }

    public function assertArrayNotHasKey(int|string $key, array $array, string $message = ''): void
    {
        $this->check(!array_key_exists($key, $array), $message, 'Unexpected key ' . self::export($key) . '.');
    }

    public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->check(str_contains($haystack, $needle), $message, self::export($haystack) . ' does not contain ' . self::export($needle) . '.');
    }

    public function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->check(!str_contains($haystack, $needle), $message, 'String unexpectedly contains ' . self::export($needle) . '.');
    }

    public function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        $this->check(str_starts_with($string, $prefix), $message, self::export($string) . ' does not start with ' . self::export($prefix) . '.');
    }

    public function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        $this->check(preg_match($pattern, $string) === 1, $message, self::export($string) . " does not match $pattern.");
    }

    public function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($actual > $expected, $message, self::export($actual) . ' is not > ' . self::export($expected) . '.');
    }

    public function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($actual >= $expected, $message, self::export($actual) . ' is not >= ' . self::export($expected) . '.');
    }

    public function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->check($actual <= $expected, $message, self::export($actual) . ' is not <= ' . self::export($expected) . '.');
    }
}

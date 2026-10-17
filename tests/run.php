<?php

declare(strict_types=1);

/*
 * Dependency-free test runner: php tests/run.php [filter]
 *
 * Runs every tests/Unit/*Test.php and tests/Integration/*Test.php class (the
 * same classes phpunit.xml points PHPUnit at). Integration tests run only
 * when CATZPRINT_TEST_FIXTURE points at a fixture JSON file.
 */

define('CATZPRINT_MINI_TESTS', true);
require __DIR__ . '/bootstrap.php';

use CatzPrint\Tests\AssertionFailed;
use CatzPrint\Tests\SkippedTest;

$filter = $argv[1] ?? null;
$passed = $failed = $skipped = $assertions = 0;
$failures = [];
$started = microtime(true);

foreach (['Unit', 'Integration'] as $suite) {
    $files = glob(__DIR__ . "/$suite/*Test.php") ?: [];
    sort($files);
    foreach ($files as $file) {
        $class = 'CatzPrint\\Tests\\' . $suite . '\\' . basename($file, '.php');
        $ref = new ReflectionClass($class);
        if ($ref->isAbstract()) {
            continue;
        }
        $methods = array_filter(
            $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m) => str_starts_with($m->getName(), 'test') && $m->getDeclaringClass()->getName() === $class,
        );
        if ($filter !== null) {
            $methods = array_filter($methods, static fn ($m) => stripos($class . '::' . $m->getName(), $filter) !== false);
        }
        if ($methods === []) {
            continue;
        }
        echo "\n$suite\\" . $ref->getShortName() . "\n";
        try {
            $class::setUpBeforeClass();
        } catch (SkippedTest $e) {
            foreach ($methods as $m) {
                echo "  - {$m->getName()} (skipped: {$e->getMessage()})\n";
                $skipped++;
            }
            continue;
        }
        foreach ($methods as $m) {
            $name = $m->getName();
            $test = new $class();
            $t0 = microtime(true);
            try {
                $test->runTestMethod($name);
                $passed++;
                printf("  ✔ %s (%.0f ms)\n", $name, (microtime(true) - $t0) * 1000);
            } catch (SkippedTest $e) {
                $skipped++;
                echo "  - $name (skipped: {$e->getMessage()})\n";
            } catch (Throwable $e) {
                $failed++;
                $kind = $e instanceof AssertionFailed ? 'Assertion failed' : get_class($e);
                echo "  ✘ $name\n";
                $failures[] = "$class::$name\n  $kind: {$e->getMessage()}\n  at {$e->getFile()}:{$e->getLine()}";
            }
            $assertions += $test->assertions;
        }
        $class::tearDownAfterClass();
    }
}

foreach ($failures as $i => $f) {
    echo "\n" . ($i + 1) . ") $f\n";
}
printf(
    "\n%s: %d passed, %d failed, %d skipped, %d assertions (%.1f s)\n",
    $failed ? 'FAILED' : 'OK',
    $passed,
    $failed,
    $skipped,
    $assertions,
    microtime(true) - $started,
);
exit($failed ? 1 : 0);

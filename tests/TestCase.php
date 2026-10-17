<?php

declare(strict_types=1);

namespace CatzPrint\Tests;

/*
 * Base class for every test. Under PHPUnit it is a real
 * PHPUnit\Framework\TestCase; under tests/run.php (no Composer, no PHPUnit)
 * it is the built-in MiniTestCase. The test classes are identical either way.
 */
if (!defined('CATZPRINT_MINI_TESTS') && class_exists(\PHPUnit\Framework\TestCase::class)) {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
    }
} else {
    abstract class TestCase extends MiniTestCase
    {
    }
}

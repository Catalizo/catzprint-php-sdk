<?php

declare(strict_types=1);

/*
 * Test bootstrap. Works with and without Composer:
 *  - with `composer install`, vendor/autoload.php is used (and PHPUnit is real);
 *  - without it, a minimal PSR-4 autoloader maps CatzPrint\ => src/ and
 *    CatzPrint\Tests\ => tests/, and tests/run.php drives the same test classes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class) use ($root): void {
    $map = [
        'CatzPrint\\Tests\\' => $root . '/tests/',
        'CatzPrint\\' => $root . '/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }

            return;
        }
    }
});

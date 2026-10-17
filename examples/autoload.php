<?php

declare(strict_types=1);

// Used by the examples when the SDK isn't installed with Composer.
// In your project, just require 'vendor/autoload.php'.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'CatzPrint\\')) {
            $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 10)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}

<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    foreach (['Shop\\' => '/app/', 'Tests\\' => '/tests/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

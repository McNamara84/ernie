<?php

declare(strict_types=1);

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

// These two libraries are copied into the isolated Linux fixture so www-data
// need not traverse a developer's or CI runner's private checkout directory.
spl_autoload_register(static function (string $class): void {
    foreach (['Monolog\\' => 'monolog', 'Psr\\Log\\' => 'psr-log'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            require __DIR__.'/libraries/'.$directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    }
});

$permission = (int) $argv[3];
$handler = $argv[1] === 'daily'
    ? new RotatingFileHandler($argv[2], 31, Level::Info, true, $permission)
    : new StreamHandler($argv[2], Level::Info, true, $permission);
$logger = new Logger('runtime-test', [$handler]);
$logger->info($argv[4]);
$logger->close();

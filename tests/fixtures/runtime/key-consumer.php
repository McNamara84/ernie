<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Env;

// Use the installed Laravel/dotenv implementations in an isolated directory
// readable by www-data, without booting the test runner's application.
spl_autoload_register(static function (string $class): void {
    foreach ([
        'Dotenv\\' => 'dotenv',
        'PhpOption\\' => 'phpoption',
        'GrahamCampbell\\ResultType\\' => 'result-type',
        'Illuminate\\Support\\' => 'support',
        'Illuminate\\Encryption\\' => 'encryption',
        'Illuminate\\Contracts\\Encryption\\' => 'encryption-contracts',
    ] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            require __DIR__.'/libraries/'.$directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        }
    }
});

if ($argv[1] === 'generate') {
    echo 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc'));
    exit;
}

Dotenv::create(Env::getRepository(), __DIR__)->safeLoad();
$encodedKey = Env::getOrFail('APP_KEY');
if (! is_string($encodedKey) || ! str_starts_with($encodedKey, 'base64:')) {
    throw new RuntimeException('No usable application key was loaded.');
}
$key = base64_decode(substr($encodedKey, 7), true);
if ($key === false) {
    throw new RuntimeException('The application key could not be decoded.');
}
$encrypter = new Encrypter($key, 'aes-256-cbc');
if ($encrypter->decryptString($encrypter->encryptString('runtime-key-probe')) !== 'runtime-key-probe') {
    throw new RuntimeException('Encryption round trip failed.');
}
echo 'key-fingerprint:'.hash('sha256', $key).PHP_EOL;

<?php

use App\Support\Database\MySqlSslOptions;

it('returns an empty array when no paths are provided', function (): void {
    expect(MySqlSslOptions::fromValues(null))->toBe([]);
});

it('includes only the certificate authority path when provided', function (): void {
    $options = MySqlSslOptions::fromValues('/etc/ssl/certs/sumariopmd-ca.crt');

    expect($options)
        ->toHaveCount(1)
        ->and($options)
        ->toHaveKey(PDO::MYSQL_ATTR_SSL_CA, '/etc/ssl/certs/sumariopmd-ca.crt');
});

it('filters out empty values', function (): void {
    $options = MySqlSslOptions::fromValues('   ', '', '/tmp/client.key');

    expect($options)
        ->toHaveCount(1)
        ->and($options)
        ->toHaveKey(PDO::MYSQL_ATTR_SSL_KEY, '/tmp/client.key');
});

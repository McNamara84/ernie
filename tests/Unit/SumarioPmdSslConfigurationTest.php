<?php

beforeEach(function () {
    $value = getenv('DB_SUMARIOPMD_SSL_CA');

    $this->previousSumarioPmdSslCa = [
        'value' => $value === false ? null : $value,
        'had' => $value !== false,
    ];
});

afterEach(function () {
    $state = $this->previousSumarioPmdSslCa;

    if ($state['had']) {
        putenv('DB_SUMARIOPMD_SSL_CA=' . $state['value']);
        $_ENV['DB_SUMARIOPMD_SSL_CA'] = $state['value'];
        $_SERVER['DB_SUMARIOPMD_SSL_CA'] = $state['value'];
    } else {
        putenv('DB_SUMARIOPMD_SSL_CA');
        unset($_ENV['DB_SUMARIOPMD_SSL_CA'], $_SERVER['DB_SUMARIOPMD_SSL_CA']);
    }
});

it('omits SUMARIOPMD SSL options when no certificate is configured', function () {
    putenv('DB_SUMARIOPMD_SSL_CA');
    unset($_ENV['DB_SUMARIOPMD_SSL_CA'], $_SERVER['DB_SUMARIOPMD_SSL_CA']);

    $config = require base_path('config/database.php');

    expect($config['connections']['metaworks']['options'])->toBe([]);
});

it('adds the configured SUMARIOPMD certificate to the connection options', function () {
    $certificatePath = base_path('docker/certs/sumariopmd-ca.pem');

    putenv('DB_SUMARIOPMD_SSL_CA=' . $certificatePath);
    $_ENV['DB_SUMARIOPMD_SSL_CA'] = $certificatePath;
    $_SERVER['DB_SUMARIOPMD_SSL_CA'] = $certificatePath;

    $config = require base_path('config/database.php');

    expect($config['connections']['metaworks']['options'][PDO::MYSQL_ATTR_SSL_CA] ?? null)
        ->toBe($certificatePath);
});

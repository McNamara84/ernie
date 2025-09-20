<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Env;
use Tests\TestCase;

uses(TestCase::class);

function setTestEnvValue(string $key, ?string $value): void
{
    if ($value === null) {
        Env::getRepository()->clear($key);
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }

    Env::getRepository()->set($key, $value);
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

beforeEach(function () {
    $this->provider = new AppServiceProvider(app());

    $this->originalEnv = [
        'CI' => Env::get('CI'),
        'DB_CONNECTION' => Env::get('DB_CONNECTION'),
        'DB_DATABASE' => Env::get('DB_DATABASE'),
    ];

    $this->originalConfig = [
        'database.default' => Config::get('database.default'),
        'database.connections.sqlite.database' => Config::get('database.connections.sqlite.database'),
    ];

});

afterEach(function () {
    foreach ($this->originalEnv as $key => $value) {
        setTestEnvValue($key, $value);
    }

    foreach ($this->originalConfig as $key => $value) {
        Config::set($key, $value);
    }
});

it('forces sqlite connection on CI for non-sqlite drivers', function (string $driver) {
    setTestEnvValue('CI', 'true');
    setTestEnvValue('DB_CONNECTION', $driver);
    setTestEnvValue('DB_DATABASE', 'database/testing-ci.sqlite');

    Config::set('database.default', $driver);
    Config::set('database.connections.sqlite.database', null);

    $expectedPath = base_path('database/testing-ci.sqlite');
    $existedBefore = is_file($expectedPath);

    try {
        $this->provider->register();

        expect(Config::get('database.default'))->toBe('sqlite');
        expect(Config::get('database.connections.sqlite.database'))->toBe($expectedPath);
        expect(Env::get('DB_CONNECTION'))->toBe('sqlite');
        expect(Env::get('DB_DATABASE'))->toBe($expectedPath);
        expect(is_file($expectedPath))->toBeTrue();
    } finally {
        if (! $existedBefore && is_file($expectedPath)) {
            unlink($expectedPath);
        }
    }
})->with(['mysql', 'mariadb']);

it('falls back to the default sqlite database when the configured name is not a path', function () {
    setTestEnvValue('CI', 'true');
    setTestEnvValue('DB_CONNECTION', 'mysql');
    setTestEnvValue('DB_DATABASE', 'laravel');

    Config::set('database.default', 'mysql');
    Config::set('database.connections.sqlite.database', null);

    $expectedPath = database_path('database.sqlite');
    $existedBefore = is_file($expectedPath);

    try {
        $this->provider->register();

        expect(Config::get('database.connections.sqlite.database'))->toBe($expectedPath);
        expect(Env::get('DB_DATABASE'))->toBe($expectedPath);
        expect(is_file($expectedPath))->toBeTrue();
    } finally {
        if (! $existedBefore && is_file($expectedPath)) {
            unlink($expectedPath);
        }
    }
});

it('retains in-memory sqlite configuration when provided', function () {
    setTestEnvValue('CI', 'true');
    setTestEnvValue('DB_CONNECTION', 'mysql');
    setTestEnvValue('DB_DATABASE', ':memory:');

    Config::set('database.default', 'mysql');
    Config::set('database.connections.sqlite.database', null);

    $this->provider->register();

    expect(Config::get('database.connections.sqlite.database'))->toBe(':memory:');
    expect(Env::get('DB_DATABASE'))->toBe(':memory:');
});

it('respects existing configuration outside of CI', function () {
    setTestEnvValue('CI', null);
    setTestEnvValue('DB_CONNECTION', 'mysql');

    Config::set('database.default', 'mysql');

    $this->provider->register();

    expect(Config::get('database.default'))->toBe('mysql');
    expect(Env::get('DB_CONNECTION'))->toBe('mysql');
});


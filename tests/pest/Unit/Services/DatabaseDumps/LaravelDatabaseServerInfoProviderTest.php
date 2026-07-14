<?php

declare(strict_types=1);

use App\Services\DatabaseDumps\LaravelDatabaseServerInfoProvider;
use Illuminate\Support\Facades\DB;

use function Tests\Unit\Services\DatabaseDumps\laravelDatabaseServerInfoProviderSocketFakeClosed;
use function Tests\Unit\Services\DatabaseDumps\resetLaravelDatabaseServerInfoProviderSocketFake;
use function Tests\Unit\Services\DatabaseDumps\setLaravelDatabaseServerInfoProviderSocketFake;

require_once __DIR__.'/LaravelDatabaseServerInfoProviderSocketFakeService.php';

covers(LaravelDatabaseServerInfoProvider::class);

beforeEach(function (): void {
    resetLaravelDatabaseServerInfoProviderSocketFake();
});

it('reads authenticated database server information when the connection works', function (): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('selectOne')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn((object) [
            'version' => '9.7.0',
            'version_comment' => 'MySQL Community Server - GPL',
            'compile_os' => 'Linux',
            'compile_machine' => 'x86_64',
        ]);

    DB::shouldReceive('connection')
        ->once()
        ->with('dump_test')
        ->andReturn($connection);

    expect((new LaravelDatabaseServerInfoProvider)->resolve('dump_test'))->toBe([
        'version' => '9.7.0',
        'version_comment' => 'MySQL Community Server - GPL',
        'compile_os' => 'Linux',
        'compile_machine' => 'x86_64',
        'source' => 'database',
    ]);
});

it('falls back to the raw handshake server version when authentication fails', function (): void {
    config()->set('database.connections.legacy_handshake', [
        'host' => 'legacy.example.test',
        'port' => 3306,
    ]);

    DB::shouldReceive('connection')
        ->once()
        ->with('legacy_handshake')
        ->andThrow(new RuntimeException('authentication failed'));

    $payload = chr(10).'5.6.36'.chr(0).'rest';
    $length = strlen($payload);
    setLaravelDatabaseServerInfoProviderSocketFake(new stdClass, [
        chr($length & 0xFF).chr(($length >> 8) & 0xFF).chr(($length >> 16) & 0xFF).chr(0),
        $payload,
    ]);

    expect((new LaravelDatabaseServerInfoProvider)->resolve('legacy_handshake'))->toBe([
        'version' => '5.6.36',
        'version_comment' => null,
        'compile_os' => null,
        'compile_machine' => null,
        'source' => 'handshake',
    ])->and(laravelDatabaseServerInfoProviderSocketFakeClosed())->toBeTrue();
});

it('returns unavailable when neither authenticated queries nor handshake metadata work', function (): void {
    config()->set('database.connections.legacy_unavailable', [
        'host' => 'legacy.example.test',
        'port' => 3306,
    ]);

    DB::shouldReceive('connection')
        ->once()
        ->with('legacy_unavailable')
        ->andThrow(new RuntimeException('authentication failed'));

    setLaravelDatabaseServerInfoProviderSocketFake(false);

    expect((new LaravelDatabaseServerInfoProvider)->resolve('legacy_unavailable'))->toBe([
        'version' => null,
        'version_comment' => null,
        'compile_os' => null,
        'compile_machine' => null,
        'source' => 'unavailable',
    ]);
});

it('treats zero-length handshake payloads as unavailable', function (): void {
    config()->set('database.connections.legacy_empty_handshake', [
        'host' => 'legacy.example.test',
        'port' => 3306,
    ]);

    DB::shouldReceive('connection')
        ->once()
        ->with('legacy_empty_handshake')
        ->andThrow(new RuntimeException('authentication failed'));

    setLaravelDatabaseServerInfoProviderSocketFake(new stdClass, [chr(0).chr(0).chr(0).chr(0)]);

    expect((new LaravelDatabaseServerInfoProvider)->resolve('legacy_empty_handshake')['source'])->toBe('unavailable')
        ->and(laravelDatabaseServerInfoProviderSocketFakeClosed())->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Services\DatabaseDumps\LaravelDatabaseServerInfoProvider;
use Illuminate\Support\Facades\DB;

covers(LaravelDatabaseServerInfoProvider::class);

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

it('returns unavailable without opening another connection when the authenticated query fails', function (): void {
    DB::shouldReceive('connection')
        ->once()
        ->with('legacy_unavailable')
        ->andThrow(new RuntimeException('authentication failed'));

    expect((new LaravelDatabaseServerInfoProvider)->resolve('legacy_unavailable'))->toBe([
        'version' => null,
        'version_comment' => null,
        'compile_os' => null,
        'compile_machine' => null,
        'source' => 'unavailable',
    ]);
});

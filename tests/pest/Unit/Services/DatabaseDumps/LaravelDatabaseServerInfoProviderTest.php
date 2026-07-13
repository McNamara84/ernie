<?php

declare(strict_types=1);

namespace App\Services\DatabaseDumps {
    final class LaravelDatabaseServerInfoProviderSocketFake
    {
        public static mixed $socket = false;

        /** @var list<string|false> */
        public static array $reads = [];

        public static bool $closed = false;

        public static function reset(): void
        {
            self::$socket = false;
            self::$reads = [];
            self::$closed = false;
        }
    }

    if (! function_exists(__NAMESPACE__.'\\fsockopen')) {
        function fsockopen(string $hostname, int $port, mixed &$error_code = null, mixed &$error_message = null, ?float $timeout = null): mixed
        {
            return LaravelDatabaseServerInfoProviderSocketFake::$socket;
        }
    }

    if (! function_exists(__NAMESPACE__.'\\stream_set_timeout')) {
        function stream_set_timeout(mixed $stream, int $seconds, int $microseconds = 0): bool
        {
            return true;
        }
    }

    if (! function_exists(__NAMESPACE__.'\\fread')) {
        function fread(mixed $stream, int $length): string|false
        {
            return array_shift(LaravelDatabaseServerInfoProviderSocketFake::$reads) ?? false;
        }
    }

    if (! function_exists(__NAMESPACE__.'\\fclose')) {
        function fclose(mixed $stream): bool
        {
            LaravelDatabaseServerInfoProviderSocketFake::$closed = true;

            return true;
        }
    }
}

namespace {
    use App\Services\DatabaseDumps\LaravelDatabaseServerInfoProvider;
    use App\Services\DatabaseDumps\LaravelDatabaseServerInfoProviderSocketFake;
    use Illuminate\Support\Facades\DB;

    covers(LaravelDatabaseServerInfoProvider::class);

    beforeEach(function (): void {
        LaravelDatabaseServerInfoProviderSocketFake::reset();
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
        LaravelDatabaseServerInfoProviderSocketFake::$socket = new stdClass;
        LaravelDatabaseServerInfoProviderSocketFake::$reads = [
            chr($length & 0xFF).chr(($length >> 8) & 0xFF).chr(($length >> 16) & 0xFF).chr(0),
            $payload,
        ];

        expect((new LaravelDatabaseServerInfoProvider)->resolve('legacy_handshake'))->toBe([
            'version' => '5.6.36',
            'version_comment' => null,
            'compile_os' => null,
            'compile_machine' => null,
            'source' => 'handshake',
        ])->and(LaravelDatabaseServerInfoProviderSocketFake::$closed)->toBeTrue();
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

        LaravelDatabaseServerInfoProviderSocketFake::$socket = false;

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

        LaravelDatabaseServerInfoProviderSocketFake::$socket = new stdClass;
        LaravelDatabaseServerInfoProviderSocketFake::$reads = [chr(0).chr(0).chr(0).chr(0)];

        expect((new LaravelDatabaseServerInfoProvider)->resolve('legacy_empty_handshake')['source'])->toBe('unavailable')
            ->and(LaravelDatabaseServerInfoProviderSocketFake::$closed)->toBeTrue();
    });
}

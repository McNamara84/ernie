<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DatabaseDumps {
    function &laravelDatabaseServerInfoProviderSocketFakeState(): array
    {
        if (! isset($GLOBALS['laravel_database_server_info_provider_socket_fake'])) {
            resetLaravelDatabaseServerInfoProviderSocketFake();
        }

        return $GLOBALS['laravel_database_server_info_provider_socket_fake'];
    }

    function resetLaravelDatabaseServerInfoProviderSocketFake(): void
    {
        $GLOBALS['laravel_database_server_info_provider_socket_fake'] = [
            'socket' => false,
            'reads' => [],
            'closed' => false,
        ];
    }

    /**
     * @param  list<string|false>  $reads
     */
    function setLaravelDatabaseServerInfoProviderSocketFake(mixed $socket, array $reads = []): void
    {
        $state = &laravelDatabaseServerInfoProviderSocketFakeState();
        $state['socket'] = $socket;
        $state['reads'] = $reads;
    }

    function laravelDatabaseServerInfoProviderSocketFakeClosed(): bool
    {
        $state = &laravelDatabaseServerInfoProviderSocketFakeState();

        return $state['closed'] === true;
    }
}

namespace App\Services\DatabaseDumps {
    use function Tests\Unit\Services\DatabaseDumps\laravelDatabaseServerInfoProviderSocketFakeState;

    if (! function_exists(__NAMESPACE__.'\\fsockopen')) {
        function fsockopen(string $hostname, int $port, mixed &$error_code = null, mixed &$error_message = null, ?float $timeout = null): mixed
        {
            $state = &laravelDatabaseServerInfoProviderSocketFakeState();

            return $state['socket'];
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
            $state = &laravelDatabaseServerInfoProviderSocketFakeState();

            return array_shift($state['reads']) ?? false;
        }
    }

    if (! function_exists(__NAMESPACE__.'\\fclose')) {
        function fclose(mixed $stream): bool
        {
            $state = &laravelDatabaseServerInfoProviderSocketFakeState();
            $state['closed'] = true;

            return true;
        }
    }
}

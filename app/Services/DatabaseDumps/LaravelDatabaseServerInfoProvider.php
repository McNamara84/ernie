<?php

declare(strict_types=1);

namespace App\Services\DatabaseDumps;

use Illuminate\Support\Facades\DB;

final class LaravelDatabaseServerInfoProvider implements DatabaseServerInfoProvider
{
    /**
     * @return array{version: string|null, version_comment: string|null, compile_os: string|null, compile_machine: string|null, source: string}
     */
    public function resolve(string $connectionName): array
    {
        try {
            $row = DB::connection($connectionName)->selectOne(
                'select version() as version, @@version_comment as version_comment, @@version_compile_os as compile_os, @@version_compile_machine as compile_machine'
            );

            return [
                'version' => is_string($row->version ?? null) ? $row->version : null,
                'version_comment' => is_string($row->version_comment ?? null) ? $row->version_comment : null,
                'compile_os' => is_string($row->compile_os ?? null) ? $row->compile_os : null,
                'compile_machine' => is_string($row->compile_machine ?? null) ? $row->compile_machine : null,
                'source' => 'database',
            ];
        } catch (\Throwable) {
            return $this->resolveFromHandshake($connectionName);
        }
    }

    /**
     * @return array{version: string|null, version_comment: string|null, compile_os: string|null, compile_machine: string|null, source: string}
     */
    private function resolveFromHandshake(string $connectionName): array
    {
        $config = config("database.connections.{$connectionName}");

        if (! is_array($config)) {
            return $this->emptyResult('unavailable');
        }

        $host = $config['host'] ?? null;
        $port = $config['port'] ?? 3306;

        if (! is_string($host) || $host === '') {
            return $this->emptyResult('unavailable');
        }

        $errorNumber = 0;
        $errorMessage = '';
        $socket = @fsockopen($host, (int) $port, $errorNumber, $errorMessage, 5.0);

        if ($socket === false) {
            return $this->emptyResult('unavailable');
        }

        stream_set_timeout($socket, 5);
        $header = fread($socket, 4);

        if ($header === false || strlen($header) !== 4) {
            fclose($socket);

            return $this->emptyResult('unavailable');
        }

        $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
        $payload = fread($socket, $length);
        fclose($socket);

        if ($payload === false || $payload === '') {
            return $this->emptyResult('unavailable');
        }

        return [
            'version' => strtok(substr($payload, 1), "\0") ?: null,
            'version_comment' => null,
            'compile_os' => null,
            'compile_machine' => null,
            'source' => 'handshake',
        ];
    }

    /**
     * @return array{version: string|null, version_comment: string|null, compile_os: string|null, compile_machine: string|null, source: string}
     */
    private function emptyResult(string $source): array
    {
        return [
            'version' => null,
            'version_comment' => null,
            'compile_os' => null,
            'compile_machine' => null,
            'source' => $source,
        ];
    }
}

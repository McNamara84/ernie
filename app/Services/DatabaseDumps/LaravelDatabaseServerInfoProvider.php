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
            // Do not open a raw socket merely to read the greeting. Closing a
            // MySQL handshake before authentication counts as an interrupted
            // connection and can eventually block this application host.
            return [
                'version' => null,
                'version_comment' => null,
                'compile_os' => null,
                'compile_machine' => null,
                'source' => 'unavailable',
            ];
        }
    }
}

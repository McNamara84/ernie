<?php

declare(strict_types=1);

namespace App\Services\DatabaseDumps;

use App\Models\DatabaseDumpExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pdo\Mysql;

final class DatabaseDumpService
{
    public function __construct(
        private readonly DatabaseDumpProcessRunner $processRunner,
        private readonly DatabaseServerInfoProvider $serverInfoProvider,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function targets(): array
    {
        $targets = config('database_dumps.targets', []);

        return is_array($targets) ? $targets : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function target(string $targetKey): array
    {
        $target = $this->targets()[$targetKey] ?? null;

        if (! is_array($target)) {
            throw new \InvalidArgumentException('Unknown database dump target.');
        }

        return $target;
    }

    public function createDump(DatabaseDumpExport $export): void
    {
        $disk = null;
        $path = null;
        $optionFile = null;
        $failedMessage = null;

        try {
            $target = $this->target($export->target_key);
            $connectionName = (string) $target['connection'];
            $connection = $this->connectionConfig($connectionName);
            $client = $this->processRunner->findDumpClient();

            if ($client === null) {
                $failedMessage = 'No mysqldump or mariadb-dump binary is available in the application container.';

                throw new \RuntimeException('No database dump client is available.');
            }

            $this->assertLocalDisk($export->disk);

            $serverInfo = $this->serverInfoProvider->resolve($connectionName);
            $flags = $this->buildDumpFlags($client, $target, $connection);
            $disk = Storage::disk($export->disk);
            $path = $export->path ?? $this->buildPath($export);
            $filename = $export->filename ?? basename($path);
            $absoluteOutputPath = $disk->path($path);

            $export->forceFill([
                'status' => DatabaseDumpExport::STATUS_RUNNING,
                'path' => $path,
                'filename' => $filename,
                'started_at' => now(),
                'server_version' => $this->formatServerVersion($serverInfo),
                'dump_client' => basename($client),
                'dump_options' => [
                    'flags' => $flags,
                    'server_info_source' => $serverInfo['source'],
                    'non_transactional_tables' => $this->nonTransactionalTables($connectionName, $export->database_name),
                    'legacy' => (bool) ($target['legacy'] ?? false),
                    'requires_legacy_ssl_probe' => (bool) ($target['requires_legacy_ssl_probe'] ?? false),
                ],
            ])->save();

            $optionFile = $this->writeTemporaryOptionFile($export, $connection);
            $command = array_merge(
                [$client, "--defaults-extra-file={$optionFile}"],
                $flags,
                [$export->database_name],
            );

            $result = $this->processRunner->run(
                command: $command,
                compressedOutputPath: $absoluteOutputPath,
                timeoutSeconds: (int) config('database_dumps.timeout_seconds', 7200),
            );

            if (! $result->successful()) {
                throw new \RuntimeException($result->errorOutput !== '' ? $result->errorOutput : 'Database dump process failed.');
            }

            clearstatcache(true, $absoluteOutputPath);

            $export->forceFill([
                'status' => DatabaseDumpExport::STATUS_COMPLETED,
                'size_bytes' => is_file($absoluteOutputPath) ? filesize($absoluteOutputPath) : null,
                'sha256' => is_file($absoluteOutputPath) ? hash_file('sha256', $absoluteOutputPath) : null,
                'finished_at' => now(),
                'error_message' => null,
            ])->save();
        } catch (\Throwable $exception) {
            if ($disk !== null && $path !== null) {
                try {
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                    }
                } catch (\Throwable) {
                    // The export is still marked failed; the job failure callback will attempt cleanup again.
                }
            }

            $this->markFailed($export, $failedMessage ?? $exception->getMessage());

            throw $exception;
        } finally {
            if ($optionFile !== null && is_file($optionFile)) {
                unlink($optionFile);
            }
        }
    }

    public function buildPath(DatabaseDumpExport $export): string
    {
        $prefix = trim((string) config('database_dumps.path_prefix', 'database-dumps'), '/');
        $databaseSlug = Str::slug($export->database_name) ?: 'database';
        $timestamp = now()->format('Ymd-His');

        return "{$prefix}/{$export->target_key}/{$export->id}/{$databaseSlug}-{$timestamp}.sql.gz";
    }

    /**
     * @param  array<string, mixed>  $target
     */
    public function databaseNameForTarget(array $target): string
    {
        $connectionName = (string) $target['connection'];
        $connection = $this->connectionConfig($connectionName);
        $database = $connection['database'] ?? null;

        if (! is_string($database) || trim($database) === '') {
            throw new \RuntimeException("Database name is missing for connection {$connectionName}.");
        }

        return $database;
    }

    public function assertLocalDisk(string $diskName): void
    {
        $disks = config('filesystems.disks', []);
        $disk = is_array($disks) ? ($disks[$diskName] ?? null) : null;

        if (! is_array($disk)) {
            throw new \RuntimeException("Database dump disk [{$diskName}] is not configured.");
        }

        $driver = $disk['driver'] ?? null;

        if ($driver !== 'local') {
            $driverName = is_scalar($driver) ? (string) $driver : 'unknown';

            throw new \RuntimeException("Database dump disk [{$diskName}] must use the local filesystem driver; configured driver is [{$driverName}].");
        }

        $root = $disk['root'] ?? null;

        if (! is_string($root) || trim($root) === '') {
            throw new \RuntimeException("Database dump disk [{$diskName}] must define a local root path.");
        }
    }

    private function markFailed(DatabaseDumpExport $export, string $message): void
    {
        $export->forceFill([
            'status' => DatabaseDumpExport::STATUS_FAILED,
            'finished_at' => now(),
            'error_message' => $this->sanitizeErrorMessage($message),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(string $connectionName): array
    {
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            throw new \RuntimeException("Database connection {$connectionName} is not configured.");
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    private function buildDumpFlags(string $client, array $target, array $connection): array
    {
        $flags = [
            '--databases',
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--triggers',
            '--hex-blob',
            '--skip-comments',
        ];

        foreach (['--no-tablespaces', '--column-statistics=0', '--set-gtid-purged=OFF'] as $optionalFlag) {
            if ($this->processRunner->supportsOption($client, $optionalFlag)) {
                $flags[] = $optionalFlag;
            }
        }

        if (($target['requires_legacy_ssl_probe'] ?? false) === true) {
            $flags[] = '--ssl';
        }

        if (! empty($connection['charset']) && is_string($connection['charset'])) {
            $flags[] = "--default-character-set={$connection['charset']}";
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function writeTemporaryOptionFile(DatabaseDumpExport $export, array $connection): string
    {
        $directory = storage_path('app/private/database-dumps/tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = "{$directory}/{$export->id}.cnf";
        $lines = ['[client]'];

        foreach ([
            'host' => $connection['host'] ?? null,
            'port' => $connection['port'] ?? null,
            'user' => $connection['username'] ?? null,
            'password' => $connection['password'] ?? null,
            'socket' => $connection['unix_socket'] ?? null,
        ] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = $this->formatOptionLine($key, (string) $value);
        }

        $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
        $sslCa = $options[Mysql::ATTR_SSL_CA] ?? null;
        $sslVerifyServerCert = $options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT] ?? null;

        if (is_string($sslCa) && $sslCa !== '') {
            $lines[] = $this->formatOptionLine('ssl-ca', $sslCa);
        }

        if ($sslVerifyServerCert === false) {
            $lines[] = 'ssl-verify-server-cert=0';
        }

        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        @chmod($path, 0600);

        return $path;
    }

    private function formatOptionLine(string $key, string $value): string
    {
        $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\"', '\n', '\r'], $value);

        return "{$key}=\"{$escaped}\"";
    }

    /**
     * @return list<string>|null
     */
    private function nonTransactionalTables(string $connectionName, string $databaseName): ?array
    {
        try {
            $rows = DB::connection($connectionName)->select(
                <<<'SQL'
select table_name
from information_schema.tables
where table_schema = ?
  and table_type = 'BASE TABLE'
  and engine is not null
  and upper(engine) not in ('INNODB', 'PERFORMANCE_SCHEMA')
order by table_name
SQL,
                [$databaseName],
            );

            $tables = [];

            foreach ($rows as $row) {
                $table = (string) ($row->table_name ?? '');

                if ($table !== '') {
                    $tables[] = $table;
                }
            }

            return $tables;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{version: string|null, version_comment: string|null, compile_os: string|null, compile_machine: string|null, source: string}  $serverInfo
     */
    private function formatServerVersion(array $serverInfo): ?string
    {
        $parts = array_filter([
            $serverInfo['version'],
            $serverInfo['version_comment'],
            $serverInfo['compile_os'],
            $serverInfo['compile_machine'],
        ], static fn (?string $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode(' | ', $parts);
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $message = preg_replace('/password\s*=\s*("[^"]*"|[^\s]+)/i', 'password=[redacted]', $message) ?? $message;
        $message = preg_replace('/--password(=|\s+)([^\s]+)/i', '--password=[redacted]', $message) ?? $message;

        return Str::limit($message, 1000, '...');
    }
}

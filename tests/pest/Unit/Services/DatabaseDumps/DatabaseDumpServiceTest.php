<?php

declare(strict_types=1);

use App\Models\DatabaseDumpExport;
use App\Models\User;
use App\Services\DatabaseDumps\DatabaseDumpProcessResult;
use App\Services\DatabaseDumps\DatabaseDumpProcessRunner;
use App\Services\DatabaseDumps\DatabaseDumpService;
use App\Services\DatabaseDumps\DatabaseServerInfoProvider;
use Illuminate\Support\Facades\Storage;

covers(DatabaseDumpService::class);

final class FakeDatabaseDumpProcessRunner implements DatabaseDumpProcessRunner
{
    public ?string $client = '/usr/bin/mysqldump';

    public DatabaseDumpProcessResult $result;

    /** @var list<string> */
    public array $lastCommand = [];

    public ?string $lastOutputPath = null;

    /**
     * @param  list<string>  $supportedOptions
     */
    public function __construct(
        public array $supportedOptions = ['--no-tablespaces', '--column-statistics', '--set-gtid-purged'],
    ) {
        $this->result = new DatabaseDumpProcessResult(0);
    }

    public function findDumpClient(): ?string
    {
        return $this->client;
    }

    public function supportsOption(string $client, string $option): bool
    {
        $optionName = str_contains($option, '=')
            ? substr($option, 0, (int) strpos($option, '='))
            : $option;

        return in_array($optionName, $this->supportedOptions, true);
    }

    public function run(array $command, string $compressedOutputPath, int $timeoutSeconds): DatabaseDumpProcessResult
    {
        $this->lastCommand = $command;
        $this->lastOutputPath = $compressedOutputPath;

        if (! is_dir(dirname($compressedOutputPath))) {
            mkdir(dirname($compressedOutputPath), 0775, true);
        }

        file_put_contents($compressedOutputPath, gzencode('fake sql dump'));

        return $this->result;
    }
}

final class FakeDatabaseServerInfoProvider implements DatabaseServerInfoProvider
{
    public function resolve(string $connectionName): array
    {
        return [
            'version' => '9.7.0',
            'version_comment' => 'MySQL Community Server - GPL',
            'compile_os' => 'Linux',
            'compile_machine' => 'x86_64',
            'source' => 'fake',
        ];
    }
}

beforeEach(function (): void {
    Storage::fake('local');

    config()->set('database.connections.dump_test', [
        'driver' => 'sqlite',
        'database' => 'ernie_test',
        'host' => 'db',
        'port' => '3306',
        'username' => 'ernie',
        'password' => 'top-secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'options' => [],
    ]);
    config()->set('database_dumps.disk', 'local');
    config()->set('database_dumps.path_prefix', 'database-dumps');
    config()->set('database_dumps.timeout_seconds', 120);
    config()->set('database_dumps.targets', [
        'ernie' => [
            'label' => 'ERNIE',
            'description' => 'Test database',
            'connection' => 'dump_test',
            'database_env' => 'DB_DATABASE',
            'legacy' => false,
        ],
    ]);
});

function databaseDumpService(FakeDatabaseDumpProcessRunner $runner): DatabaseDumpService
{
    return new DatabaseDumpService($runner, new FakeDatabaseServerInfoProvider);
}

it('creates a compressed dump and never places credentials in process arguments', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner;
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 'local',
        'path' => null,
        'filename' => null,
    ]);

    $service->createDump($export);

    $export->refresh();

    expect($export->status)->toBe(DatabaseDumpExport::STATUS_COMPLETED)
        ->and($export->path)->not->toBeNull()
        ->and($export->filename)->toEndWith('.sql.gz')
        ->and($export->size_bytes)->toBeGreaterThan(0)
        ->and($export->sha256)->toBeString()
        ->and($export->server_version)->toContain('9.7.0')
        ->and($export->dump_client)->toBe('mysqldump')
        ->and($export->dump_options['flags'])->toContain('--column-statistics=0')
        ->and(Storage::disk('local')->exists((string) $export->path))->toBeTrue();

    $commandLine = implode(' ', $runner->lastCommand);
    expect($commandLine)->not->toContain('top-secret')
        ->and($commandLine)->toContain('--defaults-extra-file=')
        ->and($commandLine)->toContain('--databases')
        ->and($commandLine)->toContain('ernie_test');

    $optionFile = collect($runner->lastCommand)
        ->first(fn (string $argument): bool => str_starts_with($argument, '--defaults-extra-file='));
    $optionPath = str_replace('--defaults-extra-file=', '', (string) $optionFile);

    expect(is_file($optionPath))->toBeFalse();
});

it('marks the export failed and removes partial files when the dump process fails', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner;
    $runner->result = new DatabaseDumpProcessResult(2, 'mysqldump failed with password=top-secret');
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 'local',
        'path' => 'database-dumps/ernie/failure.sql.gz',
        'filename' => 'failure.sql.gz',
    ]);

    expect(fn () => $service->createDump($export))->toThrow(RuntimeException::class);

    $export->refresh();

    expect($export->status)->toBe(DatabaseDumpExport::STATUS_FAILED)
        ->and($export->error_message)->toContain('password=[redacted]')
        ->and($export->error_message)->not->toContain('top-secret')
        ->and(Storage::disk('local')->exists('database-dumps/ernie/failure.sql.gz'))->toBeFalse();
});

it('fails clearly when no dump client is installed', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner;
    $runner->client = null;
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 'local',
    ]);

    expect(fn () => $service->createDump($export))->toThrow(RuntimeException::class, 'No database dump client is available.');

    expect($export->refresh()->status)->toBe(DatabaseDumpExport::STATUS_FAILED)
        ->and($export->error_message)->toBe('No mysqldump or mariadb-dump binary is available in the application container.');
});

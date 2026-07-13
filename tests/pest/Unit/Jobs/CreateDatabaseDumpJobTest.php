<?php

declare(strict_types=1);

use App\Jobs\CreateDatabaseDumpJob;
use App\Models\DatabaseDumpExport;
use App\Models\User;
use App\Services\DatabaseDumps\DatabaseDumpProcessResult;
use App\Services\DatabaseDumps\DatabaseDumpProcessRunner;
use App\Services\DatabaseDumps\DatabaseDumpService;
use App\Services\DatabaseDumps\DatabaseServerInfoProvider;
use Illuminate\Support\Facades\Storage;

covers(CreateDatabaseDumpJob::class);

final class CreateDatabaseDumpJobTestRunner implements DatabaseDumpProcessRunner
{
    public int $runs = 0;

    public function findDumpClient(): ?string
    {
        return '/usr/bin/mysqldump';
    }

    public function supportsOption(string $client, string $option): bool
    {
        return false;
    }

    public function run(array $command, string $compressedOutputPath, int $timeoutSeconds): DatabaseDumpProcessResult
    {
        $this->runs++;

        if (! is_dir(dirname($compressedOutputPath))) {
            mkdir(dirname($compressedOutputPath), 0775, true);
        }

        file_put_contents($compressedOutputPath, gzencode('job sql dump'));

        return new DatabaseDumpProcessResult(0);
    }
}

final class CreateDatabaseDumpJobTestServerInfoProvider implements DatabaseServerInfoProvider
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

    config()->set('database.connections.dump_job_test', [
        'driver' => 'sqlite',
        'database' => 'ernie_job_test',
        'host' => 'db',
        'port' => '3306',
        'username' => 'ernie',
        'password' => 'secret',
        'prefix' => '',
    ]);
    config()->set('database_dumps.disk', 'local');
    config()->set('database_dumps.path_prefix', 'database-dumps');
    config()->set('database_dumps.timeout_seconds', 120);
    config()->set('database_dumps.targets', [
        'ernie' => [
            'label' => 'ERNIE',
            'description' => 'Test database',
            'connection' => 'dump_job_test',
            'legacy' => false,
        ],
    ]);
});

function createDatabaseDumpJobTestService(CreateDatabaseDumpJobTestRunner $runner): DatabaseDumpService
{
    return new DatabaseDumpService($runner, new CreateDatabaseDumpJobTestServerInfoProvider);
}

it('creates a dump for an existing export', function (): void {
    $runner = new CreateDatabaseDumpJobTestRunner;
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'dump_job_test',
        'database_name' => 'ernie_job_test',
        'disk' => 'local',
        'path' => 'database-dumps/ernie/job.sql.gz',
        'filename' => 'job.sql.gz',
    ]);

    (new CreateDatabaseDumpJob($export->id))->handle(createDatabaseDumpJobTestService($runner));

    expect($runner->runs)->toBe(1)
        ->and($export->refresh()->status)->toBe(DatabaseDumpExport::STATUS_COMPLETED);
});

it('quietly ignores missing exports', function (): void {
    $runner = new CreateDatabaseDumpJobTestRunner;

    (new CreateDatabaseDumpJob('missing-export-id'))->handle(createDatabaseDumpJobTestService($runner));

    expect($runner->runs)->toBe(0);
});

it('marks existing exports as failed when the queued job fails before handling', function (): void {
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->running()->create();

    (new CreateDatabaseDumpJob($export->id))->failed(new RuntimeException('mysqldump password=secret failed'));

    expect($export->refresh()->status)->toBe(DatabaseDumpExport::STATUS_FAILED)
        ->and($export->error_message)->toContain('password=[redacted]')
        ->and($export->error_message)->not->toContain('secret');
});

it('ignores failure callbacks for exports that no longer exist', function (): void {
    (new CreateDatabaseDumpJob('missing-export-id'))->failed(new RuntimeException('failed'));

    expect(DatabaseDumpExport::query()->count())->toBe(0);
});

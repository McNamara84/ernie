<?php

declare(strict_types=1);

use App\Models\DatabaseDumpExport;
use App\Models\User;
use App\Services\DatabaseDumps\DatabaseDumpProcessResult;
use App\Services\DatabaseDumps\DatabaseDumpProcessRunner;
use App\Services\DatabaseDumps\DatabaseDumpService;
use App\Services\DatabaseDumps\DatabaseServerInfoProvider;
use Illuminate\Support\Facades\Storage;
use Pdo\Mysql;

covers(DatabaseDumpService::class);

final class FakeDatabaseDumpProcessRunner implements DatabaseDumpProcessRunner
{
    public ?string $client = '/usr/bin/mysqldump';

    public DatabaseDumpProcessResult $result;

    /** @var list<string> */
    public array $lastCommand = [];

    public ?string $requiredBinary = null;

    public ?string $lastOptionFileContents = null;

    public ?string $lastOutputPath = null;

    public int $runs = 0;

    /**
     * @param  list<string>  $supportedOptions
     */
    public function __construct(
        public array $supportedOptions = ['--no-tablespaces', '--column-statistics', '--set-gtid-purged'],
    ) {
        $this->result = new DatabaseDumpProcessResult(0);
    }

    public function findDumpClient(?string $requiredBinary = null): ?string
    {
        $this->requiredBinary = $requiredBinary;

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
        $this->runs++;

        $optionFile = collect($command)
            ->first(fn (string $argument): bool => str_starts_with($argument, '--defaults-extra-file='));
        $optionPath = str_replace('--defaults-extra-file=', '', (string) $optionFile);
        $contents = is_file($optionPath) ? file_get_contents($optionPath) : false;
        $this->lastOptionFileContents = is_string($contents) ? $contents : null;

        if (! is_dir(dirname($compressedOutputPath))) {
            mkdir(dirname($compressedOutputPath), 0775, true);
        }

        file_put_contents($compressedOutputPath, gzencode('fake sql dump'));

        return $this->result;
    }
}

final class FakeDatabaseServerInfoProvider implements DatabaseServerInfoProvider
{
    /**
     * @var array{version: string|null, version_comment: string|null, compile_os: string|null, compile_machine: string|null, source: string}
     */
    public array $result = [
        'version' => '9.7.0',
        'version_comment' => 'MySQL Community Server - GPL',
        'compile_os' => 'Linux',
        'compile_machine' => 'x86_64',
        'source' => 'fake',
    ];

    public function resolve(string $connectionName): array
    {
        return $this->result;
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
            'legacy' => false,
        ],
    ]);
});

function databaseDumpService(
    FakeDatabaseDumpProcessRunner $runner,
    ?FakeDatabaseServerInfoProvider $serverInfoProvider = null,
): DatabaseDumpService {
    return new DatabaseDumpService($runner, $serverInfoProvider ?? new FakeDatabaseServerInfoProvider);
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

it('marks the export failed when the dump target is unknown', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner;
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'unknown',
        'connection_name' => 'missing',
        'database_name' => 'missing',
        'disk' => 'local',
    ]);

    expect(fn () => $service->createDump($export))->toThrow(InvalidArgumentException::class, 'Unknown database dump target.');

    expect($runner->runs)->toBe(0)
        ->and($export->refresh()->status)->toBe(DatabaseDumpExport::STATUS_FAILED)
        ->and($export->error_message)->toBe('Unknown database dump target.');
});

it('marks the export failed when the configured database connection is missing', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner;
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();
    config()->set('database_dumps.targets.ernie.connection', 'missing_dump_connection');
    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'missing_dump_connection',
        'database_name' => 'ernie_test',
        'disk' => 'local',
    ]);

    expect(fn () => $service->createDump($export))
        ->toThrow(RuntimeException::class, 'Database connection missing_dump_connection is not configured.');

    expect($runner->runs)->toBe(0)
        ->and($export->refresh()->status)->toBe(DatabaseDumpExport::STATUS_FAILED)
        ->and($export->error_message)->toBe('Database connection missing_dump_connection is not configured.');
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

it('keeps TLS but disables certificate verification for a self-signed dump target', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner([
        '--no-tablespaces',
        '--column-statistics',
        '--set-gtid-purged',
        '--ssl-verify-server-cert',
    ]);
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();

    config()->set('database.connections.dump_test.options', [
        Mysql::ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt',
    ]);
    config()->set('database_dumps.targets.ernie.ssl_verify_server_cert', false);

    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 'local',
    ]);

    $service->createDump($export);

    expect($runner->lastOptionFileContents)->toContain('ssl-verify-server-cert=0')
        ->and($runner->lastOptionFileContents)->toContain('ssl-ca=')
        ->and($runner->lastCommand)->not->toContain('--ssl-mode=REQUIRED')
        ->and($export->refresh()->dump_options['ssl_verify_server_cert'])->toBeFalse();
});

it('uses ssl mode instead of a MariaDB option for an Oracle dump client', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner([
        '--no-tablespaces',
        '--column-statistics',
        '--set-gtid-purged',
        '--ssl-mode',
    ]);
    $runner->client = '/usr/local/bin/mysql-8-mysqldump';
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();

    config()->set('database.connections.dump_test.options', [
        Mysql::ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt',
    ]);
    config()->set('database_dumps.targets.ernie.ssl_verify_server_cert', false);

    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 'local',
    ]);

    $service->createDump($export);

    expect($runner->lastCommand)->toContain('--ssl-mode=REQUIRED')
        ->and($runner->lastOptionFileContents)->not->toContain('ssl-verify-server-cert')
        ->and($runner->lastOptionFileContents)->not->toContain('ssl-ca');
});

it('does not emit unsupported TLS verification options', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner;
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();

    config()->set('database_dumps.targets.ernie.ssl_verify_server_cert', false);

    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 'local',
    ]);

    $service->createDump($export);

    expect($runner->lastCommand)->not->toContain('--ssl-mode=REQUIRED')
        ->and($runner->lastOptionFileContents)->not->toContain('ssl-verify-server-cert');
});

it('uses the configured version hint without retrying metadata queries after a connection failure', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner;
    $serverInfoProvider = new FakeDatabaseServerInfoProvider;
    $serverInfoProvider->result = [
        'version' => null,
        'version_comment' => null,
        'compile_os' => null,
        'compile_machine' => null,
        'source' => 'unavailable',
    ];
    $service = databaseDumpService($runner, $serverInfoProvider);
    $admin = User::factory()->admin()->create();

    config()->set('database_dumps.targets.ernie.server_version_hint', 'MySQL Community Server 9.7.0');

    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 'local',
    ]);

    $service->createDump($export);

    $export->refresh();

    expect($export->server_version)->toBe('MySQL Community Server 9.7.0')
        ->and($export->dump_options['server_info_source'])->toBe('hint')
        ->and($export->dump_options['non_transactional_tables'])->toBeNull();
});

it('uses the required Oracle client and compatible SSL options for MySQL 5.6', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner([
        '--no-tablespaces',
        '--column-statistics',
        '--set-gtid-purged',
        '--ssl-mode',
    ]);
    $runner->client = '/usr/local/bin/mysql-legacy-mysqldump';
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();

    config()->set('database.connections.dump_test.options', [
        Mysql::ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt',
        Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
    ]);
    config()->set('database_dumps.targets.igsn', [
        'label' => 'IGSN',
        'description' => 'Legacy IGSN database',
        'connection' => 'dump_test',
        'dump_binary' => '/usr/local/bin/mysql-legacy-mysqldump',
        'legacy' => true,
        'requires_legacy_ssl_probe' => true,
    ]);

    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'igsn',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 'local',
    ]);

    $service->createDump($export);

    expect($runner->requiredBinary)->toBe('/usr/local/bin/mysql-legacy-mysqldump')
        ->and($runner->lastCommand)->toContain('--ssl-mode=PREFERRED')
        ->and($runner->lastCommand)->not->toContain('--ssl')
        ->and($runner->lastOptionFileContents)->not->toContain('ssl-verify-server-cert')
        ->and($runner->lastOptionFileContents)->not->toContain('ssl-ca');
});

it('fails clearly instead of falling back when a required target client is missing', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner;
    $runner->client = null;
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();

    config()->set('database_dumps.targets.igsn', [
        'label' => 'IGSN',
        'description' => 'Legacy IGSN database',
        'connection' => 'dump_test',
        'dump_binary' => '/usr/local/bin/mysql-legacy-mysqldump',
        'legacy' => true,
    ]);

    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'igsn',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 'local',
    ]);

    expect(fn () => $service->createDump($export))
        ->toThrow(RuntimeException::class, 'No database dump client is available.');

    expect($runner->requiredBinary)->toBe('/usr/local/bin/mysql-legacy-mysqldump')
        ->and($runner->runs)->toBe(0)
        ->and($export->refresh()->error_message)
        ->toBe('The required database dump client [mysql-legacy-mysqldump] is not available in the application container.');
});

it('fails clearly when the configured dump disk is not local', function (): void {
    $runner = new FakeDatabaseDumpProcessRunner;
    $service = databaseDumpService($runner);
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->create([
        'target_key' => 'ernie',
        'connection_name' => 'dump_test',
        'database_name' => 'ernie_test',
        'disk' => 's3',
    ]);

    expect(fn () => $service->createDump($export))
        ->toThrow(RuntimeException::class, 'Database dump disk [s3] must use the local filesystem driver');

    expect($runner->runs)->toBe(0)
        ->and($export->refresh()->status)->toBe(DatabaseDumpExport::STATUS_FAILED)
        ->and($export->error_message)->toBe('Database dump disk [s3] must use the local filesystem driver; configured driver is [s3].');
});

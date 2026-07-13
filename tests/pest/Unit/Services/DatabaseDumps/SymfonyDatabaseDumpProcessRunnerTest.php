<?php

declare(strict_types=1);

use App\Services\DatabaseDumps\SymfonyDatabaseDumpProcessRunner;

covers(SymfonyDatabaseDumpProcessRunner::class);

beforeEach(function (): void {
    config()->set('database_dumps.dump_binary', null);
});

it('prefers a configured executable dump client', function (): void {
    config()->set('database_dumps.dump_binary', PHP_BINARY);

    expect((new SymfonyDatabaseDumpProcessRunner)->findDumpClient())->toBe(PHP_BINARY);
});

it('detects supported and unsupported client options', function (): void {
    $runner = new SymfonyDatabaseDumpProcessRunner;
    $client = storage_path('framework/testing/fake-mysqldump-client');

    file_put_contents($client, <<<'PHP'
#!/usr/bin/env php
<?php
echo "--column-statistics\n--set-gtid-purged\n";
PHP);
    chmod($client, 0755);

    expect($runner->supportsOption($client, '--column-statistics=0'))->toBeTrue()
        ->and($runner->supportsOption($client, '--definitely-not-a-real-option'))->toBeFalse();
});

it('streams process stdout into a gzip file and captures stderr', function (): void {
    $runner = new SymfonyDatabaseDumpProcessRunner;
    $outputPath = storage_path('framework/testing/database-dumps/runner-output.sql.gz');

    $result = $runner->run(
        command: [PHP_BINARY, '-r', 'fwrite(STDOUT, "dump sql"); fwrite(STDERR, "warning");'],
        compressedOutputPath: $outputPath,
        timeoutSeconds: 10,
    );

    expect($result->successful())->toBeTrue()
        ->and($result->errorOutput)->toBe('warning')
        ->and(gzdecode((string) file_get_contents($outputPath)))->toBe('dump sql');
});

it('returns non-zero exit codes and keeps stderr bounded', function (): void {
    $runner = new SymfonyDatabaseDumpProcessRunner;
    $outputPath = storage_path('framework/testing/database-dumps/runner-failure.sql.gz');

    $result = $runner->run(
        command: [PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("x", 13050)); exit(7);'],
        compressedOutputPath: $outputPath,
        timeoutSeconds: 10,
    );

    expect($result->exitCode)->toBe(7)
        ->and($result->successful())->toBeFalse()
        ->and(strlen($result->errorOutput))->toBeLessThanOrEqual(12000)
        ->and(gzdecode((string) file_get_contents($outputPath)))->toBe('');
});

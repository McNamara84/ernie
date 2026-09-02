<?php

declare(strict_types=1);

use App\Enums\LogDownloadPeriod;
use App\Services\LogDownloadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

uses()->group('serial');

covers(LogDownloadService::class);

beforeEach(function (): void {
    $this->logDirectory = storage_path('logs');
    File::ensureDirectoryExists($this->logDirectory);
    $this->logBackups = [];
    $this->additionalPaths = [];

    foreach (File::files($this->logDirectory) as $file) {
        $filename = $file->getFilename();
        if ($filename !== 'laravel.log' && preg_match('/^laravel-\d{4}-\d{2}-\d{2}\.log$/', $filename) !== 1) {
            continue;
        }

        $backupPath = $file->getPathname().'.download-test-'.uniqid('', true).'.backup';
        File::move($file->getPathname(), $backupPath);
        $this->logBackups[$backupPath] = $file->getPathname();
    }

    $this->service = new LogDownloadService;
    $this->endsAt = CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC');
});

afterEach(function (): void {
    foreach (File::files($this->logDirectory) as $file) {
        $filename = $file->getFilename();
        if ($filename === 'laravel.log' || preg_match('/^laravel-\d{4}-\d{2}-\d{2}\.log$/', $filename) === 1) {
            File::delete($file->getPathname());
        }
    }

    foreach ($this->additionalPaths as $path) {
        if (is_link($path)) {
            unlink($path);
        } elseif (File::exists($path)) {
            File::delete($path);
        }
    }

    foreach ($this->logBackups as $backupPath => $originalPath) {
        if (File::exists($backupPath)) {
            File::move($backupPath, $originalPath);
        }
    }
});

function renderLogDownload(
    LogDownloadService $service,
    LogDownloadPeriod $period,
    CarbonImmutable $startsAt,
    CarbonImmutable $endsAt,
): string {
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Unable to create a temporary test stream.');
    }

    try {
        $service->writeTextExport($stream, $period, $startsAt, $endsAt);
        rewind($stream);
        $content = stream_get_contents($stream);

        if ($content === false) {
            throw new RuntimeException('Unable to read the temporary test stream.');
        }

        return $content;
    } finally {
        fclose($stream);
    }
}

it('writes an explanatory text export when no log files exist', function (): void {
    $content = renderLogDownload(
        $this->service,
        LogDownloadPeriod::DAY,
        LogDownloadPeriod::DAY->startsAt($this->endsAt),
        $this->endsAt,
    );

    expect($content)
        ->toContain('# ERNIE application log export')
        ->toContain('# Period: Last 24 hours')
        ->toContain('# From (UTC): 2026-09-01T12:00:00Z')
        ->toContain('# To (UTC): 2026-09-02T12:00:00Z')
        ->toContain('# Generated (UTC): 2026-09-02T12:00:00Z')
        ->toEndWith("# No log entries were available for this period.\n");
});

it('includes both rolling boundaries and excludes entries outside them', function (): void {
    File::put($this->logDirectory.'/laravel.log', <<<'LOG'
[2026-09-01 11:59:59] production.INFO: Before start
[2026-09-01 12:00:00] production.INFO: Exact start
[2026-09-02 08:00:00] production.WARNING: Inside period
[2026-09-02 12:00:00] production.ERROR: Exact end
[2026-09-02 12:00:01] production.INFO: After end
LOG);

    $content = renderLogDownload(
        $this->service,
        LogDownloadPeriod::DAY,
        LogDownloadPeriod::DAY->startsAt($this->endsAt),
        $this->endsAt,
    );

    expect($content)
        ->not->toContain('Before start')
        ->toContain('[2026-09-01 12:00:00] production.INFO: Exact start')
        ->toContain('[2026-09-02 08:00:00] production.WARNING: Inside period')
        ->toContain('[2026-09-02 12:00:00] production.ERROR: Exact end')
        ->not->toContain('After end');
});

it('preserves multiline log entries and stack traces', function (): void {
    $rawLog = <<<'LOG'
[2026-09-02 10:00:00] production.ERROR: Database connection failed {"attempt":3}
#0 /var/www/app/Service.php(42): connect()
#1 /var/www/app/Controller.php(18): run()
[2026-09-02 10:01:00] production.INFO: Recovery completed
LOG;
    File::put($this->logDirectory.'/laravel.log', $rawLog);

    $content = renderLogDownload(
        $this->service,
        LogDownloadPeriod::DAY,
        LogDownloadPeriod::DAY->startsAt($this->endsAt),
        $this->endsAt,
    );

    expect($content)->toContain($rawLog);
});

it('merges active and rotated files in global chronological order', function (): void {
    File::put($this->logDirectory.'/laravel-2026-09-01.log', <<<'LOG'
[2026-09-01 13:00:00] production.INFO: First rotated
[2026-09-02 09:00:00] production.INFO: Third rotated
LOG);
    File::put($this->logDirectory.'/laravel.log', <<<'LOG'
[2026-09-02 08:00:00] production.INFO: Second active
[2026-09-02 10:00:00] production.INFO: Fourth active
LOG);

    $content = renderLogDownload(
        $this->service,
        LogDownloadPeriod::DAY,
        LogDownloadPeriod::DAY->startsAt($this->endsAt),
        $this->endsAt,
    );

    $positions = [
        strpos($content, 'First rotated'),
        strpos($content, 'Second active'),
        strpos($content, 'Third rotated'),
        strpos($content, 'Fourth active'),
    ];

    expect($positions)->each->toBeInt()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());
});

it('uses source name and entry sequence for deterministic equal timestamps', function (): void {
    File::put($this->logDirectory.'/laravel-2026-09-02.log', <<<'LOG'
[2026-09-02 10:00:00] production.INFO: Rotated first
[2026-09-02 10:00:00] production.INFO: Rotated second
LOG);
    File::put($this->logDirectory.'/laravel.log', "[2026-09-02 10:00:00] production.INFO: Active third\n");

    $content = renderLogDownload(
        $this->service,
        LogDownloadPeriod::DAY,
        LogDownloadPeriod::DAY->startsAt($this->endsAt),
        $this->endsAt,
    );

    expect(strpos($content, 'Rotated first'))->toBeLessThan(strpos($content, 'Rotated second'))
        ->and(strpos($content, 'Rotated second'))->toBeLessThan(strpos($content, 'Active third'));
});

it('adds a separator when an entry at a file boundary has no trailing newline', function (): void {
    File::put($this->logDirectory.'/laravel-2026-09-01.log', '[2026-09-02 09:00:00] production.INFO: No newline');
    File::put($this->logDirectory.'/laravel.log', "[2026-09-02 10:00:00] production.INFO: Next entry\n");

    $content = renderLogDownload(
        $this->service,
        LogDownloadPeriod::DAY,
        LogDownloadPeriod::DAY->startsAt($this->endsAt),
        $this->endsAt,
    );

    expect($content)->toContain("production.INFO: No newline\n[2026-09-02 10:00:00] production.INFO: Next entry");
});

it('ignores unrelated files invalid rotation dates and symlinks', function (): void {
    $unrelatedPath = $this->logDirectory.'/application.log';
    $invalidDatePath = $this->logDirectory.'/laravel-2026-13-40.log';
    $symlinkPath = $this->logDirectory.'/laravel-2026-09-01.log';
    $outsidePath = tempnam(sys_get_temp_dir(), 'ernie-log-download-');

    expect($outsidePath)->toBeString();

    File::put($unrelatedPath, "[2026-09-02 09:00:00] production.INFO: Unrelated file\n");
    File::put($invalidDatePath, "[2026-09-02 09:00:00] production.INFO: Invalid date filename\n");
    File::put($outsidePath, "[2026-09-02 09:00:00] production.INFO: Symlink target\n");
    expect(symlink($outsidePath, $symlinkPath))->toBeTrue();

    $this->additionalPaths = [$unrelatedPath, $invalidDatePath, $symlinkPath, $outsidePath];
    File::put($this->logDirectory.'/laravel.log', "[2026-09-02 10:00:00] production.INFO: Supported file\n");

    $content = renderLogDownload(
        $this->service,
        LogDownloadPeriod::DAY,
        LogDownloadPeriod::DAY->startsAt($this->endsAt),
        $this->endsAt,
    );

    expect($content)
        ->toContain('Supported file')
        ->not->toContain('Unrelated file')
        ->not->toContain('Invalid date filename')
        ->not->toContain('Symlink target');
});

it('does not attach malformed entries or leading context to neighboring records', function (): void {
    File::put($this->logDirectory.'/laravel.log', <<<'LOG'
orphaned context before an entry
[2026-09-02 09:00:00] production.INFO: Valid entry
valid context
[2026-02-31 09:30:00] production.ERROR: Invalid timestamp
invalid context
[2026-09-02 10:00:00] production.INFO: Later valid entry
LOG);

    $content = renderLogDownload(
        $this->service,
        LogDownloadPeriod::DAY,
        LogDownloadPeriod::DAY->startsAt($this->endsAt),
        $this->endsAt,
    );

    expect($content)
        ->not->toContain('orphaned context')
        ->toContain("production.INFO: Valid entry\nvalid context")
        ->not->toContain('Invalid timestamp')
        ->not->toContain('invalid context')
        ->toContain('Later valid entry');
});

it('rejects non-stream output targets', function (): void {
    $this->service->writeTextExport(
        'not-a-stream',
        LogDownloadPeriod::DAY,
        LogDownloadPeriod::DAY->startsAt($this->endsAt),
        $this->endsAt,
    );
})->throws(InvalidArgumentException::class, 'The log export target must be a writable stream.');

it('defaults daily log retention to 31 days', function (): void {
    expect(config('logging.channels.daily.days'))->toBe(31);
});

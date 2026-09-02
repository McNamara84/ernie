<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

uses()->group('serial');

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 12:00:00', 'UTC'));
    $this->logDirectory = storage_path('logs');
    File::ensureDirectoryExists($this->logDirectory);
    $this->logBackups = [];

    foreach (File::files($this->logDirectory) as $file) {
        $filename = $file->getFilename();
        if ($filename !== 'laravel.log' && preg_match('/^laravel-\d{4}-\d{2}-\d{2}\.log$/', $filename) !== 1) {
            continue;
        }

        $backupPath = $file->getPathname().'.download-controller-test-'.uniqid('', true).'.backup';
        File::move($file->getPathname(), $backupPath);
        $this->logBackups[$backupPath] = $file->getPathname();
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    foreach (File::files($this->logDirectory) as $file) {
        $filename = $file->getFilename();
        if ($filename === 'laravel.log' || preg_match('/^laravel-\d{4}-\d{2}-\d{2}\.log$/', $filename) === 1) {
            File::delete($file->getPathname());
        }
    }

    foreach ($this->logBackups as $backupPath => $originalPath) {
        if (File::exists($backupPath)) {
            File::move($backupPath, $originalPath);
        }
    }
});

it('requires authentication for log downloads', function (): void {
    $this->get(route('logs.download', ['period' => 'day']))
        ->assertRedirect(route('login'));
});

it('forbids every non-admin role from downloading logs', function (string $factoryState): void {
    $user = User::factory()->{$factoryState}()->create();

    $this->actingAs($user)
        ->get(route('logs.download', ['period' => 'day']))
        ->assertForbidden();
})->with([
    'beginner' => 'beginner',
    'curator' => 'curator',
    'group leader' => 'groupLeader',
]);

it('returns 404 for an unsupported period', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/logs/download/year')
        ->assertNotFound();
});

it('streams each supported period with stable headers and metadata', function (
    string $period,
    string $periodLabel,
    string $filenameSegment,
    string $expectedStart,
): void {
    File::put($this->logDirectory.'/laravel.log', "[2026-09-02 10:00:00] production.INFO: Downloaded entry\n");
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->get(route('logs.download', ['period' => $period]));

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain("ernie-logs-{$filenameSegment}-20260902T120000Z.txt")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $content = $response->streamedContent();

    expect($content)
        ->toContain("# Period: {$periodLabel}")
        ->toContain("# From (UTC): {$expectedStart}")
        ->toContain('# To (UTC): 2026-09-02T12:00:00Z')
        ->toContain('Downloaded entry');
})->with([
    'day' => ['day', 'Last 24 hours', 'last-24-hours', '2026-09-01T12:00:00Z'],
    'week' => ['week', 'Last 7 days', 'last-7-days', '2026-08-26T12:00:00Z'],
    'month' => ['month', 'Last 30 days', 'last-30-days', '2026-08-03T12:00:00Z'],
]);

it('normalizes the request time to seconds before calculating inclusive boundaries', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 12:00:00.500000', 'UTC'));
    File::put($this->logDirectory.'/laravel.log', "[2026-09-01 12:00:00] production.INFO: Exact displayed start\n");
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->get(route('logs.download', ['period' => 'day']));

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain('# From (UTC): 2026-09-01T12:00:00Z')
        ->toContain('[2026-09-01 12:00:00] production.INFO: Exact displayed start');
});

it('ignores level and search query parameters', function (): void {
    File::put($this->logDirectory.'/laravel.log', <<<'LOG'
[2026-09-02 10:00:00] production.INFO: Included despite level
[2026-09-02 11:00:00] production.ERROR: Included despite search
LOG);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->get(route('logs.download', [
            'period' => 'day',
            'level' => 'error',
            'search' => 'missing phrase',
        ]));

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain('Included despite level')
        ->toContain('Included despite search');
});

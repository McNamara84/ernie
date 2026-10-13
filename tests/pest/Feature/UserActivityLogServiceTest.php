<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Services\ApplicationLogSourceService;
use App\Services\LogService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->originalStorage = app()->storagePath();
    $this->testStorage = sys_get_temp_dir().'/ernie-activity-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->testStorage.'/logs');
    app()->useStoragePath($this->testStorage);
});

afterEach(function () {
    app()->useStoragePath($this->originalStorage);
    File::deleteDirectory($this->testStorage);
});

it('merges daily and single logs with unique entry identities and deterministic ordering', function () {
    File::put(storage_path('logs/laravel.log'), "[2026-10-09 10:00:00] local.INFO: Single\n");
    File::put(storage_path('logs/laravel-2026-10-09.log'), "[2026-10-09 10:00:00] production.INFO: Daily\n");
    $logs = app(LogService::class)->getLogs();
    expect($logs['data'])->toHaveCount(2)
        ->and(array_unique(array_column($logs['data'], 'entry_id')))->toHaveCount(2);
    expect(app(LogService::class)->getLogs()['data'])->toBe($logs['data']);
});

it('extracts activity with braces and quotes in the title and generates safe links', function () {
    $resource = Resource::factory()->create(['doi' => '10.5880/example']);
    $activity = ['schema_version' => 1, 'action' => 'resource.updated', 'actor' => ['id' => 7, 'name' => 'Alice'],
        'subject' => ['id' => $resource->id, 'title' => 'A {title} "quoted"', 'doi' => $resource->doi],
        'dataset_path' => 'javascript:alert(1)', 'doi_url' => 'https://evil.example'];
    File::put(storage_path('logs/laravel.log'), '[2026-10-09 10:00:00] local.INFO: Alice updated A {title} "quoted". '.json_encode(['activity' => $activity])."\n");
    $entry = app(LogService::class)->getLogs()['data'][0];
    expect($entry['message'])->toBe('Alice updated A {title} "quoted".')
        ->and($entry['activity']['dataset_path'])->toBe('/editor?resourceId='.$resource->id)
        ->and($entry['activity']['doi_url'])->toBe('https://doi.org/10.5880/example');
    expect(app(LogService::class)->getLogs(search: '10.5880/example')['total'])->toBe(1);
});

it('keeps malformed or unknown activity payloads as ordinary log text', function (string $context) {
    File::put(storage_path('logs/laravel.log'), "[2026-10-09 10:00:00] local.INFO: Message {$context}\n#0 technical trace\n");
    $entry = app(LogService::class)->getLogs()['data'][0];
    expect($entry['activity'])->toBeNull()->and($entry['message'])->toContain($context)->and($entry['context'])->toBe('#0 technical trace');
})->with(['{"activity": invalid}', '{"activity":{"schema_version":2}}', '{"user_id":7}']);

it('links IGSN activity to the identifier search', function () {
    $resource = Resource::factory()->create(['doi' => '10.60510/example']);
    $activity = ['schema_version' => 1, 'action' => 'igsn.imported', 'actor' => ['name' => 'Alice'],
        'subject' => ['id' => $resource->id, 'kind' => 'IGSN', 'doi' => $resource->doi]];
    File::put(storage_path('logs/laravel.log'), '[2026-10-09 10:00:00] local.INFO: Imported. '.json_encode(['activity' => $activity])."\n");
    expect(app(LogService::class)->getLogs()['data'][0]['activity']['dataset_path'])->toBe('/igsns?search=10.60510%2Fexample');
});

it('omits links for missing records and test-mode or invalid identifiers', function (mixed $doi, bool $testMode) {
    $activity = ['schema_version' => 1, 'action' => 'resource.updated', 'actor' => ['id' => 7, 'name' => 'Alice'],
        'subject' => ['id' => 999999, 'doi' => $doi], 'test_mode' => $testMode];
    File::put(storage_path('logs/laravel.log'), '[2026-10-09 10:00:00] local.INFO: Message '.json_encode(['activity' => $activity])."\n");
    $entry = app(LogService::class)->getLogs()['data'][0];
    expect($entry['activity']['dataset_path'])->toBeNull()->and($entry['activity']['doi_url'])->toBeNull();
})->with([[null, false], ['javascript:alert(1)', false], ['10.5880/example', true]]);

it('deletes the selected daily entry without touching an identical timestamp in another file', function () {
    $line = "[2026-10-09 10:00:00] local.INFO: Same timestamp\n";
    File::put(storage_path('logs/laravel.log'), $line);
    File::put(storage_path('logs/laravel-2026-10-09.log'), $line);
    $service = app(LogService::class);
    $entry = array_values(array_filter($service->getLogs()['data'], fn (array $entry): bool => str_contains(base64_decode($entry['entry_id']), 'laravel-2026')))[0];
    expect($service->deleteLogEntry($entry['line_number'], $entry['timestamp'], $entry['entry_id']))->toBeTrue()
        ->and(File::get(storage_path('logs/laravel.log')))->toBe($line)
        ->and(File::get(storage_path('logs/laravel-2026-10-09.log')))->toBe('');
});

it('rejects stale entry identities after a previous entry has been removed', function () {
    File::put(storage_path('logs/laravel.log'), "[2026-10-09 10:00:00] local.INFO: First\n[2026-10-09 10:00:00] local.INFO: Second\n");
    $service = app(LogService::class);
    [$second, $first] = $service->getLogs()['data'];
    expect($service->deleteLogEntry(2, $first['timestamp'], $first['entry_id']))->toBeTrue()
        ->and($service->deleteLogEntry(2, $second['timestamp'], $second['entry_id']))->toBeFalse()
        ->and(File::get(storage_path('logs/laravel.log')))->toContain('Second');
});

it('rejects path traversal and clears only supported application log sources', function () {
    foreach (['laravel.log', 'laravel-2026-10-09.log', 'laravel-2026-99-99.log', 'other.log'] as $name) {
        File::put(storage_path('logs/'.$name), "[2026-10-09 10:00:00] local.INFO: Keep\n");
    }
    $service = app(LogService::class);
    expect(app(ApplicationLogSourceService::class)->sources())->toHaveCount(2);
    expect($service->deleteLogEntry(1, '2026-10-09 10:00:00', base64_encode(json_encode(['../other.log', 0, 'invalid']))))->toBeFalse()
        ->and($service->clearLogs())->toBeTrue()
        ->and(File::get(storage_path('logs/other.log')))->not->toBe('')
        ->and(File::get(storage_path('logs/laravel-2026-99-99.log')))->not->toBe('');
});

it('bounds the viewer to 50 MB and reports omitted history', function () {
    $path = storage_path('logs/laravel-2026-10-09.log');
    $handle = fopen($path, 'w+b');
    ftruncate($handle, 51 * 1024 * 1024);
    fseek($handle, -100, SEEK_END);
    fwrite($handle, "\n[2026-10-09 10:00:00] local.INFO: Recent entry\n");
    fclose($handle);
    File::put(storage_path('logs/laravel-2026-10-08.log'), "[2026-10-08 10:00:00] local.INFO: Older entry\n");
    touch($path, 1); // Simulate deletion/maintenance touching the older file most recently.
    $logs = app(LogService::class)->getLogs();
    expect($logs['truncated'])->toBeTrue()->and($logs['data'])->toHaveCount(1)->and($logs['data'][0]['message'])->toBe('Recent entry');
});

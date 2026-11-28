<?php

declare(strict_types=1);

use App\Services\News\NewsLocalTimeService;
use Illuminate\Validation\ValidationException;

it('resolves Berlin dates independently of the configured timezone', function (string $local, ?string $offset, string $utc): void {
    config(['app.timezone' => 'Pacific/Honolulu']);
    expect(app(NewsLocalTimeService::class)->parse($local, $offset, 'starts_at')->toIso8601String())->toBe($utc);
})->with([
    ['2030-01-15T12:00', null, '2030-01-15T11:00:00+00:00'],
    ['2030-07-15T12:00', null, '2030-07-15T10:00:00+00:00'],
    ['2030-10-27T02:30', '+02:00', '2030-10-27T00:30:00+00:00'],
    ['2030-10-27T02:30', '+01:00', '2030-10-27T01:30:00+00:00'],
    ['2030-03-31T01:30', '+01:00', '2030-03-31T00:30:00+00:00'],
    ['2030-03-31T03:30', '+02:00', '2030-03-31T01:30:00+00:00'],
]);

it('rejects nonexistent ambiguous and incorrect-offset local times', function (string $local, ?string $offset, string $field): void {
    try {
        app(NewsLocalTimeService::class)->parse($local, $offset, 'starts_at');
        $this->fail('Expected a local time validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    ['2030-03-31T02:30', null, 'starts_at'],
    ['2030-10-27T02:30', null, 'starts_at_offset'],
    ['2030-01-15T12:00', '+02:00', 'starts_at_offset'],
    ['2030-07-15T12:00', '+01:00', 'starts_at_offset'],
]);

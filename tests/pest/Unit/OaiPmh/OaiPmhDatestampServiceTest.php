<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Services\OaiPmh\OaiPmhDatestampService;

it('initializes missing timestamps and advances old ones without moving timestamps backwards', function (?string $previous, string $expected) {
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(9, 0));
    $resource = Resource::factory()->create();
    Resource::withoutTimestamps(fn () => Resource::whereKey($resource->id)->update(['updated_at' => $previous]));
    app(OaiPmhDatestampService::class)->touchResource($resource->id);
    expect($resource->fresh()->updated_at->toIso8601ZuluString())->toBe($expected);
})->with([
    'missing' => [null, '2026-10-05T09:00:00Z'],
    'older' => ['2026-10-05 08:00:00', '2026-10-05T09:00:00Z'],
    'same second' => ['2026-10-05 09:00:00', '2026-10-05T09:00:00Z'],
    'future' => ['2026-10-05 10:00:00', '2026-10-05T10:00:00Z'],
]);

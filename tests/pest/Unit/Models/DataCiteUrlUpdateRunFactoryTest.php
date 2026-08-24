<?php

declare(strict_types=1);

use App\Enums\DataCiteUrlUpdateRunStatus;
use App\Models\DataCiteUrlUpdateRun;

it('creates multiple inactive completed runs without violating the active marker constraint', function (): void {
    $runs = DataCiteUrlUpdateRun::factory()->count(2)->create();

    expect($runs)->toHaveCount(2)
        ->and($runs->every(fn (DataCiteUrlUpdateRun $run): bool => $run->status === DataCiteUrlUpdateRunStatus::COMPLETED))->toBeTrue()
        ->and($runs->every(fn (DataCiteUrlUpdateRun $run): bool => $run->active_marker === null))->toBeTrue()
        ->and($runs->every(fn (DataCiteUrlUpdateRun $run): bool => $run->completed_at !== null))->toBeTrue();
});

it('provides an explicit internally consistent active state', function (): void {
    $run = DataCiteUrlUpdateRun::factory()->active()->create();

    expect($run->status)->toBe(DataCiteUrlUpdateRunStatus::QUEUED)
        ->and($run->active_marker)->toBe(DataCiteUrlUpdateRun::ACTIVE_MARKER)
        ->and($run->completed_at)->toBeNull();
});

<?php

declare(strict_types=1);

use App\Enums\AssessmentRunStatus;
use App\Models\AssessmentRun;
use App\Services\Assessment\AssessmentRunPresenterService;

test('the run presenter prefers an actionable pause reason over the raw error', function (): void {
    $run = AssessmentRun::factory()->create([
        'status' => AssessmentRunStatus::PAUSED,
        'pause_reason' => 'The assessment worker failed unexpectedly. Resume the run after checking the logs.',
        'last_error' => 'Connection refused by 172.20.0.9:1071.',
        'paused_at' => now(),
    ]);

    $presented = app(AssessmentRunPresenterService::class)->present($run);

    expect($presented['error'])->toBe('The assessment worker failed unexpectedly. Resume the run after checking the logs.');
});

test('the run presenter falls back to the raw error when no pause reason exists', function (): void {
    $run = AssessmentRun::factory()->create([
        'status' => AssessmentRunStatus::FAILED,
        'active_scope' => null,
        'pause_reason' => null,
        'last_error' => 'Assessment preparation failed.',
    ]);

    $presented = app(AssessmentRunPresenterService::class)->present($run);

    expect($presented['error'])->toBe('Assessment preparation failed.');
});

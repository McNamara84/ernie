<?php

declare(strict_types=1);

use App\Enums\AssessmentRunStatus;
use App\Enums\AssessmentScope;
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

test('the run presenter uses scope-appropriate capitalization in running progress', function (
    AssessmentScope $scope,
    string $expectedProgress,
): void {
    $run = AssessmentRun::factory()->create([
        'scope' => $scope,
        'active_scope' => $scope,
        'status' => AssessmentRunStatus::RUNNING,
        'total' => 10,
        'processed' => 4,
        'pending' => 6,
        'started_at' => now(),
    ]);

    $presented = app(AssessmentRunPresenterService::class)->present($run);

    expect($presented['progress'])->toBe($expectedProgress);
})->with([
    'resources' => [AssessmentScope::RESOURCE, 'Assessing resources 4 of 10...'],
    'IGSNs' => [AssessmentScope::IGSN, 'Assessing IGSNs 4 of 10...'],
]);

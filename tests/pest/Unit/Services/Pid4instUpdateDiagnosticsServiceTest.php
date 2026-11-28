<?php

declare(strict_types=1);

use App\Services\Pid4instUpdateDiagnosticsService;

covers(Pid4instUpdateDiagnosticsService::class);

it('returns no warning for legacy or malformed diagnostic lists', function (array $snapshot): void {
    expect(Pid4instUpdateDiagnosticsService::fromSnapshot($snapshot))->toBe([
        'excludedCount' => 0, 'exclusions' => [], 'refreshPending' => false,
    ]);
})->with([
    [[]], [['_sync' => null]], [['_sync' => ['excluded' => 'invalid']]],
    [['_sync' => ['excluded' => [null, 'invalid', ['id' => 7], []]]]],
]);

it('bounds public settings details while retaining the complete count and safe reason codes', function (): void {
    $excluded = array_map(fn (int $number): array => [
        'id' => 'record-'.$number, 'reasonCode' => 'unregistered_handle', 'reason' => 'Private diagnostic',
    ], range(1, 12));
    $summary = Pid4instUpdateDiagnosticsService::fromSnapshot(['_sync' => ['excluded' => $excluded, 'refreshPending' => true]]);
    expect($summary['excludedCount'])->toBe(12)->and($summary['exclusions'])->toHaveCount(10)
        ->and($summary['exclusions'][0])->toBe(['id' => 'record-1', 'reasonCode' => 'unregistered_handle'])
        ->and($summary['refreshPending'])->toBeTrue();
});

it('does not infer a reason code from an older free text message', function (): void {
    $summary = Pid4instUpdateDiagnosticsService::fromSnapshot(['_sync' => ['excluded' => [
        ['id' => 'old', 'reason' => 'Metadata Handle is not registered and has no ePIC provider PID.'],
        ['id' => 'malformed', 'reasonCode' => []],
    ], 'refreshPending' => 'true']]);
    expect(array_column($summary['exclusions'], 'reasonCode'))->toBe(['invalid_record', 'invalid_record'])
        ->and($summary['refreshPending'])->toBeFalse();
});

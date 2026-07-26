<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Assistance\AssistantContract;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\Assistance\BatchSuggestionActionService;
use App\Services\Assistance\BatchSuggestionValidationException;

covers(BatchSuggestionActionService::class, BatchSuggestionValidationException::class);

function reviewSuggestion(int $id, int $resourceId, ?string $target = null, bool $canAccept = true): array
{
    return [
        'id' => $id,
        'resource_id' => $resourceId,
        'resource_doi' => '10.1234/resource',
        'review' => [
            'assistant_id' => 'test-assistant',
            'assistant_name' => 'Test assistant',
            'route_prefix' => 'test',
            'can_accept' => $canAccept,
            'can_decline' => true,
            'exclusive_target_key' => $target,
            'label' => 'Candidate '.$id,
        ],
    ];
}

function registerBatchAssistant(array $suggestions): array
{
    $registrar = new AssistantRegistrar;
    $assistant = Mockery::mock(AssistantContract::class);
    $assistant->shouldReceive('getId')->andReturn('test-assistant');
    $assistant->shouldReceive('getName')->andReturn('Test assistant');
    $assistant->shouldReceive('getSuggestionForReview')
        ->andReturnUsing(fn (int $id): ?array => $suggestions[$id] ?? null);
    $registrar->register($assistant);

    return [$registrar, $assistant];
}

it('executes a validated batch and returns complete per-item feedback', function (): void {
    [$registrar, $assistant] = registerBatchAssistant([
        1 => reviewSuggestion(1, 10),
        2 => reviewSuggestion(2, 10),
    ]);
    $assistant->shouldReceive('acceptSuggestion')->once()->with(1)->andReturn([
        'success' => true,
        'message' => 'Accepted first.',
        'synced_dois' => ['10.1/a'],
        'bulk_affiliation_match' => [
            'available' => true,
            'count' => 2,
            'bulk_token' => 'token',
            'creator_name' => 'Ada Lovelace',
            'affiliation' => 'Analytical Engine Institute',
            'suggested_ror_id' => 'https://ror.org/012345678',
        ],
    ]);
    $assistant->shouldReceive('acceptSuggestion')->once()->with(2)->andReturn([
        'success' => false,
        'message' => 'Stale suggestion.',
        'synced_dois' => [],
    ]);

    $result = (new BatchSuggestionActionService($registrar))->execute('accept', 10, [
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 1],
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 2],
    ], new User);

    expect($result['success'])->toBeFalse()
        ->and($result['success_count'])->toBe(1)
        ->and($result['failure_count'])->toBe(1)
        ->and($result['synced_dois'])->toBe(['10.1/a'])
        ->and($result['follow_ups'])->toHaveCount(1)
        ->and($result['results'])->toHaveCount(2)
        ->and($result['results'][1]['message'])->toBe('Stale suggestion.');
});

it('allows multiple exclusive alternatives to be declined', function (): void {
    [$registrar, $assistant] = registerBatchAssistant([
        1 => reviewSuggestion(1, 10, 'person:5'),
        2 => reviewSuggestion(2, 10, 'person:5'),
    ]);
    $user = new User;
    $assistant->shouldReceive('declineSuggestion')->once()->with(1, $user, 'No match')->andReturn(['success' => true, 'message' => 'Declined.']);
    $assistant->shouldReceive('declineSuggestion')->once()->with(2, $user, 'No match')->andReturn(['success' => true, 'message' => 'Declined.']);

    $result = (new BatchSuggestionActionService($registrar))->execute('decline', 10, [
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 1],
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 2],
    ], $user, 'No match');

    expect($result['success'])->toBeTrue()
        ->and($result['success_count'])->toBe(2)
        ->and($result['message'])->toContain('2 suggestion(s) declined');
});

it('rejects conflicting alternatives before executing a mutation', function (): void {
    [$registrar, $assistant] = registerBatchAssistant([
        1 => reviewSuggestion(1, 10, 'person:5'),
        2 => reviewSuggestion(2, 10, 'person:5'),
    ]);
    $assistant->shouldNotReceive('acceptSuggestion');

    expect(fn () => (new BatchSuggestionActionService($registrar))->execute('accept', 10, [
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 1],
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 2],
    ], new User))
        ->toThrow(BatchSuggestionValidationException::class, 'Only one alternative per target can be accepted.');
});

it('rejects decline-only hints when accepting', function (): void {
    [$registrar, $assistant] = registerBatchAssistant([1 => reviewSuggestion(1, 10, null, false)]);
    $assistant->shouldNotReceive('acceptSuggestion');

    expect(fn () => (new BatchSuggestionActionService($registrar))->execute('accept', 10, [
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 1],
    ], new User))
        ->toThrow(BatchSuggestionValidationException::class, 'can only be declined');
});

it('rejects duplicate identities and cross-resource selections', function (array $selections, int $resourceId, string $message): void {
    [$registrar, $assistant] = registerBatchAssistant([1 => reviewSuggestion(1, 10)]);
    $assistant->shouldNotReceive('acceptSuggestion');

    expect(fn () => (new BatchSuggestionActionService($registrar))->execute('accept', $resourceId, $selections, new User))
        ->toThrow(BatchSuggestionValidationException::class, $message);
})->with([
    'duplicate' => [[
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 1],
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 1],
    ], 10, 'cannot be selected twice'],
    'cross-resource' => [[
        ['assistant_id' => 'test-assistant', 'suggestion_id' => 1],
    ], 11, 'must belong to the requested resource'],
]);

it('rejects unknown assistants, missing suggestions, and unknown actions', function (Closure $execute, string $message): void {
    [$registrar] = registerBatchAssistant([]);
    $service = new BatchSuggestionActionService($registrar);

    expect(fn () => $execute($service))->toThrow(BatchSuggestionValidationException::class, $message);
})->with([
    'assistant' => [
        fn (BatchSuggestionActionService $service) => $service->execute('accept', 10, [
            ['assistant_id' => 'unknown', 'suggestion_id' => 1],
        ], new User),
        'Unknown assistant',
    ],
    'suggestion' => [
        fn (BatchSuggestionActionService $service) => $service->execute('accept', 10, [
            ['assistant_id' => 'test-assistant', 'suggestion_id' => 999],
        ], new User),
        'was not found',
    ],
    'action' => [
        fn (BatchSuggestionActionService $service) => $service->execute('archive', 10, [], new User),
        'Unknown batch action',
    ],
]);

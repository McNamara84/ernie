<?php

declare(strict_types=1);

use App\Services\Assistance\AssistanceReviewService;
use App\Services\Assistance\AssistantContract;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\DoiSuggestionService;
use App\Services\Resources\ResourceImpactFilterService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

covers(AssistanceReviewService::class);

function reviewServiceItem(
    string $assistantId,
    int $id,
    int $resourceId,
    float $score,
    ?string $target,
): array {
    return [
        'id' => $id,
        'assistant_id' => $assistantId,
        'resource_id' => $resourceId,
        'resource_doi' => '10.1234/'.$resourceId,
        'resource_title' => 'Resource '.$resourceId,
        'similarity_score' => $score,
        'review' => [
            'assistant_id' => $assistantId,
            'assistant_name' => $assistantId,
            'route_prefix' => $assistantId,
            'can_accept' => true,
            'can_decline' => true,
            'exclusive_target_key' => $target,
            'label' => 'Candidate '.$id,
        ],
    ];
}

function reviewServiceAssistant(string $id, array $resources, array $suggestions): AssistantContract
{
    $assistant = Mockery::mock(AssistantContract::class);
    $assistant->shouldReceive('getId')->andReturn($id);
    $timestamps = collect($resources)->pluck('resource_created_at_timestamp', 'resource_id');
    $assistant->shouldReceive('pendingSuggestionImpactQuery')->andReturnUsing(function () use ($id, $suggestions, $timestamps) {
        $combined = null;

        foreach ($suggestions as $suggestion) {
            $query = DB::query()->selectRaw(
                '? AS assistant_id, ? AS suggestion_id, ? AS resource_id, ? AS impact_resource_id, ? AS resource_created_at',
                [
                    $id,
                    $suggestion['id'],
                    $suggestion['resource_id'],
                    $suggestion['resource_id'],
                    (int) $timestamps->get($suggestion['resource_id'], 0),
                ],
            );

            if ($combined === null) {
                $combined = $query;
            } else {
                $combined->unionAll($query);
            }
        }

        return $combined ?? DB::query()
            ->selectRaw('NULL AS assistant_id, NULL AS suggestion_id, NULL AS resource_id, NULL AS impact_resource_id, NULL AS resource_created_at')
            ->whereRaw('1 = 0');
    });
    $assistant->shouldReceive('loadSuggestionsForResources')
        ->andReturnUsing(fn (array $resourceIds, ?array $suggestionIds = null): array => array_values(array_filter(
            $suggestions,
            fn (array $suggestion): bool => in_array($suggestion['resource_id'], $resourceIds, true)
                && ($suggestionIds === null || in_array($suggestion['id'], $suggestionIds, true)),
        )));

    return $assistant;
}

it('paginates resources while hydrating every suggestion for the visible resource', function (): void {
    $registrar = new AssistantRegistrar;
    $registrar->register(reviewServiceAssistant('assistant-a', [
        ['resource_id' => 10, 'resource_created_at_timestamp' => 1_774_173_600],
        ['resource_id' => 20, 'resource_created_at_timestamp' => 1_774_087_200],
    ], [
        reviewServiceItem('assistant-a', 1, 10, 0.5, 'person:1'),
        reviewServiceItem('assistant-a', 2, 10, 0.9, 'person:1'),
        reviewServiceItem('assistant-a', 3, 20, 0.7, null),
    ]));
    $registrar->register(reviewServiceAssistant('assistant-b', [
        ['resource_id' => 10, 'resource_created_at_timestamp' => 1_774_173_600],
    ], [
        reviewServiceItem('assistant-b', 4, 10, 0.8, 'only-one-candidate'),
    ]));

    $request = Request::create('/assistance', 'GET', ['all_page' => 1]);
    LengthAwarePaginator::currentPageResolver(fn (string $pageName = 'page'): int => (int) $request->query($pageName, 1));

    $result = (new AssistanceReviewService(
        $registrar,
        new ResourceImpactFilterService(new DoiSuggestionService),
    ))->build($request, 1);
    $allGroup = $result['allAssistantResources']->items()[0];

    expect($result['allAssistantResources']->total())->toBe(2)
        ->and($allGroup['resource_id'])->toBe(10)
        ->and($allGroup['suggestion_count'])->toBe(3)
        ->and(array_column($allGroup['suggestions'], 'id'))->toBe([2, 1, 4])
        ->and($allGroup['suggestions'][0]['review']['exclusive_target_key'])->toBe('person:1')
        ->and($allGroup['suggestions'][2]['review']['exclusive_target_key'])->toBeNull()
        ->and($result['sections']['assistant-a']->total())->toBe(2)
        ->and($result['sections']['assistant-a']->items()[0]['suggestion_count'])->toBe(2)
        ->and($result['pendingCounts'])->toBe(['assistant-a' => 3, 'assistant-b' => 1]);
});

it('resolves all-assistant and per-assistant page parameters independently', function (): void {
    $registrar = new AssistantRegistrar;
    $registrar->register(reviewServiceAssistant('assistant-a', [
        ['resource_id' => 10, 'resource_created_at_timestamp' => 1_774_173_600],
        ['resource_id' => 20, 'resource_created_at_timestamp' => 1_774_087_200],
    ], [
        reviewServiceItem('assistant-a', 1, 10, 0.9, null),
        reviewServiceItem('assistant-a', 2, 20, 0.8, null),
    ]));
    $request = Request::create('/assistance', 'GET', ['all_page' => 2, 'assistant-a_page' => 1]);
    LengthAwarePaginator::currentPageResolver(fn (string $pageName = 'page'): int => (int) $request->query($pageName, 1));

    $result = (new AssistanceReviewService(
        $registrar,
        new ResourceImpactFilterService(new DoiSuggestionService),
    ))->build($request, 1);

    expect($result['allAssistantResources']->currentPage())->toBe(2)
        ->and($result['allAssistantResources']->items()[0]['resource_id'])->toBe(20)
        ->and($result['sections']['assistant-a']->currentPage())->toBe(1)
        ->and($result['sections']['assistant-a']->items()[0]['resource_id'])->toBe(10);
});

it('uses the newest numeric creation timestamp when assistants report the same resource', function (): void {
    $registrar = new AssistantRegistrar;
    $registrar->register(reviewServiceAssistant('assistant-a', [
        ['resource_id' => 10, 'resource_created_at_timestamp' => 100],
        ['resource_id' => 20, 'resource_created_at_timestamp' => 200],
    ], [
        reviewServiceItem('assistant-a', 1, 10, 0.9, null),
        reviewServiceItem('assistant-a', 2, 20, 0.8, null),
    ]));
    $registrar->register(reviewServiceAssistant('assistant-b', [
        ['resource_id' => 10, 'resource_created_at_timestamp' => 300],
    ], [
        reviewServiceItem('assistant-b', 3, 10, 0.7, null),
    ]));
    $request = Request::create('/assistance');
    LengthAwarePaginator::currentPageResolver(static fn (): int => 1);

    $result = (new AssistanceReviewService(
        $registrar,
        new ResourceImpactFilterService(new DoiSuggestionService),
    ))->build($request, 25);

    expect(array_column($result['allAssistantResources']->items(), 'resource_id'))->toBe([10, 20]);
});

it('returns only requested resource ids affected by pending suggestions', function (): void {
    $registrar = new AssistantRegistrar;
    $registrar->register(reviewServiceAssistant('assistant-a', [
        ['resource_id' => 10, 'resource_created_at_timestamp' => 100],
        ['resource_id' => 20, 'resource_created_at_timestamp' => 200],
    ], [
        reviewServiceItem('assistant-a', 1, 10, 0.9, null),
        reviewServiceItem('assistant-a', 2, 20, 0.8, null),
    ]));
    $service = new AssistanceReviewService(
        $registrar,
        new ResourceImpactFilterService(new DoiSuggestionService),
    );

    expect($service->resourceIdsWithPendingSuggestions([30, 20, 20]))->toBe([20])
        ->and($service->resourceIdsWithPendingSuggestions([]))->toBe([]);
});

<?php

declare(strict_types=1);

use App\Http\Controllers\AssistanceDataController;
use App\Models\Person;
use App\Models\Resource;
use App\Models\User;
use App\Services\Assistance\AssistanceReviewService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

uses()->group('performance', 'mysql-sensitive');

covers(AssistanceDataController::class, AssistanceReviewService::class);

it('keeps the unfiltered assistance scope independent of shared-person impact fan-out', function (): void {
    $user = User::factory()->create(['role' => 'admin']);
    $person = Person::factory()->create();
    $origin = Resource::factory()->withDoi('10.5880/assistance-performance-origin')->create();
    $resourceTemplate = $origin->getAttributes();
    unset($resourceTemplate['id']);

    $resourceRows = [];
    foreach (range(1, 4000) as $index) {
        $resourceRows[] = [
            ...$resourceTemplate,
            'doi' => sprintf('10.5880/assistance-performance-%04d', $index),
        ];

        if (count($resourceRows) === 500) {
            DB::table('resources')->insert($resourceRows);
            $resourceRows = [];
        }
    }

    $resourceIds = Resource::query()
        ->where('doi', 'like', '10.5880/assistance-performance-%')
        ->where('id', '!=', $origin->id)
        ->orderBy('id')
        ->pluck('id')
        ->prepend($origin->id)
        ->values();
    $now = now();

    foreach ($resourceIds->chunk(500) as $chunk) {
        DB::table('resource_contributors')->insert($chunk->values()->map(
            static fn (int $resourceId): array => [
                'resource_id' => $resourceId,
                'contributorable_type' => Person::class,
                'contributorable_id' => $person->id,
                'position' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        )->all());
    }

    $suggestionOrigins = $resourceIds->take(100)->values();
    $suggestionRows = [];
    foreach (range(1, 2000) as $index) {
        $suggestionRows[] = [
            'resource_id' => $suggestionOrigins[($index - 1) % $suggestionOrigins->count()],
            'person_id' => $person->id,
            'suggested_orcid' => sprintf('0000-0001-%04d-%04d', intdiv($index, 10_000), $index % 10_000),
            'similarity_score' => 0.9,
            'candidate_first_name' => 'Performance',
            'candidate_last_name' => 'Candidate',
            'candidate_affiliations' => '[]',
            'source_context' => 'contributor',
            'discovered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($suggestionRows) === 500) {
            DB::table('suggested_orcids')->insert($suggestionRows);
            $suggestionRows = [];
        }
    }

    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $startedAt = hrtime(true);
    $response = $this->actingAs($user)->getJson('/assistance/data/all?per_page=25');
    $elapsedMilliseconds = (int) round((hrtime(true) - $startedAt) / 1_000_000);

    $response
        ->assertOk()
        ->assertJsonPath('total', 100)
        ->assertJsonCount(25, 'data');

    $expandedImpactQueries = array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'impact_creators') || str_contains($sql, 'impact_contributors'),
    );

    expect($expandedImpactQueries)->toBeEmpty('The unfiltered endpoint must not expand suggestions across shared persons.')
        ->and(count($queries))->toBeLessThan(40)
        ->and($elapsedMilliseconds)->toBeLessThan(
            10_000,
            "The production-shaped unfiltered request took {$elapsedMilliseconds} ms.",
        );
});

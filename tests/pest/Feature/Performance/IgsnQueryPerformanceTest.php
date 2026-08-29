<?php

declare(strict_types=1);

use App\Http\Controllers\IgsnController;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

covers(IgsnController::class);

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);

    $physicalObjectTypeId = ResourceType::query()
        ->where('slug', 'physical-object')
        ->value('id');
    $mainTitleTypeId = TitleType::query()
        ->where('slug', 'MainTitle')
        ->value('id');

    expect($physicalObjectTypeId)->toBeInt()
        ->and($mainTitleTypeId)->toBeInt();

    foreach (range(1, 100) as $index) {
        $resource = Resource::query()->create([
            'doi' => sprintf('10.60516/PERF%04d', $index),
            'publication_year' => 2025,
            'resource_type_id' => $physicalObjectTypeId,
        ]);

        $resource->titles()->create([
            'value' => "Performance sample {$index}",
            'title_type_id' => $mainTitleTypeId,
        ]);

        IgsnMetadata::query()->create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);
    }

    $this->user = User::factory()->admin()->create();
});

it('keeps IGSN list queries bounded as the page size grows', function (): void {
    // Warm shared Inertia props and their counters before measuring the route.
    $this->actingAs($this->user)->get('/igsns?per_page=10')->assertOk();

    $captureQueries = function (int $perPage): array {
        /** @var list<string> $queries */
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        $this->actingAs($this->user)
            ->get('/igsns?per_page='.$perPage)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('igsns', $perPage));

        return $queries;
    };

    $tenRowQueries = $captureQueries(10);
    $hundredRowQueries = $captureQueries(100);

    $lookupQueryCount = static fn (array $queries): int => count(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'title_types') || str_contains($sql, 'date_types'),
    ));

    expect(count($hundredRowQueries) - count($tenRowQueries))
        ->toBeLessThanOrEqual(2, 'IGSN list query count must not grow with the number of transformed rows')
        ->and($lookupQueryCount($tenRowQueries))->toBeLessThanOrEqual(2)
        ->and($lookupQueryCount($hundredRowQueries))->toBeLessThanOrEqual(2);
});

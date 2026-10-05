<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\OaiPmhHarvest;
use App\Models\Resource;
use App\Services\OaiPmh\OaiPmhHarvestService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('captures the complete ordered inventory using database inserts rather than identity reads', function () {
    $first = Resource::factory()->create(['updated_at' => now()->subHours(2)]);
    $second = Resource::factory()->create(['updated_at' => now()->subHour()]);
    LandingPage::factory()->published()->create(['resource_id' => $first->id, 'published_at' => now()]);
    LandingPage::factory()->published()->create(['resource_id' => $second->id, 'published_at' => now()->subHour()]);
    $deleted = OaiPmhDeletedRecord::create([
        'oai_identifier' => 'oai:example.org:deleted', 'doi' => '10.5880/deleted', 'datestamp' => now(), 'sets' => [],
    ]);
    $resources = Resource::query()->join('landing_pages', 'landing_pages.resource_id', '=', 'resources.id');
    DB::enableQueryLog();
    try {
        $harvest = app(OaiPmhHarvestService::class)->create($resources, OaiPmhDeletedRecord::query());
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')))->toHaveCount(0)
        ->and($queries->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'insert into') && str_contains($sql, 'ROW_NUMBER()')))->toHaveCount(2)
        ->and($harvest->item_count)->toBe(3)
        ->and(app(OaiPmhHarvestService::class)->page($harvest, 0, 3))->toBe([
            ['kind' => 'deleted', 'id' => $deleted->id],
            ['kind' => 'resource', 'id' => $second->id],
            ['kind' => 'resource', 'id' => $first->id],
        ]);
});

it('rolls back an incomplete inventory if capturing resource identities fails', function () {
    OaiPmhDeletedRecord::create([
        'oai_identifier' => 'oai:example.org:deleted', 'doi' => '10.5880/deleted', 'datestamp' => now(), 'sets' => [],
    ]);
    $resources = Resource::query()->join('landing_pages', 'landing_pages.resource_id', '=', 'resources.id')
        ->whereRaw('resources.nonexistent_column = 1');
    expect(fn () => app(OaiPmhHarvestService::class)->create($resources, OaiPmhDeletedRecord::query()))->toThrow(QueryException::class);
    expect(OaiPmhHarvest::count())->toBe(0)
        ->and(DB::table('oai_pmh_harvest_items')->count())->toBe(0)
        ->and(OaiPmhDeletedRecord::count())->toBe(1);
});

it('reads only the indexed position range from a large snapshot', function () {
    $harvest = OaiPmhHarvest::create(['item_count' => 5000, 'expires_at' => now()->addDay()]);
    $other = OaiPmhHarvest::create(['item_count' => 1, 'expires_at' => now()->addDay()]);
    for ($start = 0; $start < 5000; $start += 250) {
        DB::table('oai_pmh_harvest_items')->insert(array_map(fn (int $position): array => [
            'harvest_id' => $harvest->id, 'position' => $position,
            'kind' => 'resource', 'identity_id' => 10000 + $position,
        ], range($start, $start + 249)));
    }
    DB::table('oai_pmh_harvest_items')->insert([
        'harvest_id' => $other->id, 'position' => 0, 'kind' => 'resource', 'identity_id' => 99999,
    ]);
    $service = app(OaiPmhHarvestService::class);
    DB::enableQueryLog();
    try {
        $page = $service->page($harvest, 2450, 7);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($page)->toBe(array_map(fn (int $position): array => ['kind' => 'resource', 'id' => 10000 + $position], range(2450, 2456)))
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['bindings'])->toBe([$harvest->id, 2450, 2457])
        ->and(strtolower($queries[0]['query']))->not->toContain('offset')
        ->and($service->page($harvest, 4998, 7))->toHaveCount(2)
        ->and($service->page($harvest, 5000, 7))->toBe([])
        ->and($harvest->getAttributes())->not->toHaveKey('items');

    $sql = 'SELECT kind, identity_id FROM oai_pmh_harvest_items WHERE harvest_id = ? AND position >= ? AND position < ? ORDER BY position';
    if (DB::getDriverName() === 'mysql') {
        $plan = DB::select('EXPLAIN FORMAT=TRADITIONAL '.$sql, [$harvest->id, 2450, 2457])[0];
        expect($plan->key)->toBe('PRIMARY')->and($plan->type)->toBe('range');
    } else {
        $plan = DB::select('EXPLAIN QUERY PLAN '.$sql, [$harvest->id, 2450, 2457])[0];
        expect($plan->detail)->toContain('SEARCH', 'harvest_id=?', 'position>?', 'position<?');
    }
});

it('cleans up snapshot rows for empty and single-page responses', function () {
    $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc')->assertOk();
    $resource = Resource::factory()->create();
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $this->get('/oai-pmh?verb=ListIdentifiers&metadataPrefix=oai_dc')->assertOk();
    expect(OaiPmhHarvest::count())->toBe(0)
        ->and(DB::table('oai_pmh_harvest_items')->count())->toBe(0);
});

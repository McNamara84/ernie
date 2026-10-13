<?php

declare(strict_types=1);

use App\Models\OaiPmhHarvest;
use App\Models\OaiPmhResumptionToken;
use App\Services\OaiPmh\OaiPmhHarvestService;
use App\Services\OaiPmh\OaiPmhResumptionTokenService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

it('rolls back snapshot storage without losing existing resumption tokens', function () {
    $token = app(OaiPmhResumptionTokenService::class)->create('ListRecords', 'oai_dc', null, null, null, 1, 3);
    /** @var Migration $normalization */
    $normalization = require database_path('migrations/2026_10_05_000005_normalize_oai_pmh_harvest_items.php');
    $normalization->down();
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_05_000004_create_oai_pmh_harvests_table.php');
    $migration->down();
    try {
        expect(Schema::hasTable('oai_pmh_harvests'))->toBeFalse()
            ->and(Schema::hasColumn('oai_pmh_resumption_tokens', 'harvest_id'))->toBeFalse()
            ->and(Schema::hasColumn('oai_pmh_resumption_tokens', 'harvest_position'))->toBeFalse()
            ->and(OaiPmhResumptionToken::find($token->id)->token)->toBe($token->token);
    } finally {
        $migration->up();
        $normalization->up();
    }
    expect(Schema::hasTable('oai_pmh_harvests'))->toBeTrue()
        ->and($token->fresh()->harvest_id)->toBeNull();
});

it('indexes harvest expiration and cascades tokens when a snapshot is removed', function () {
    $indexes = collect(Schema::getIndexes('oai_pmh_harvests'));
    expect($indexes->contains(fn (array $index): bool => $index['columns'] === ['expires_at']))->toBeTrue();
    $harvest = OaiPmhHarvest::create(['item_count' => 1, 'expires_at' => now()->addDay()]);
    DB::table('oai_pmh_harvest_items')->insert([
        'harvest_id' => $harvest->id, 'position' => 0, 'kind' => 'resource', 'identity_id' => 123,
    ]);
    $token = app(OaiPmhResumptionTokenService::class)->create('ListRecords', 'oai_dc', 'epos-msl', null, null, 1, 2, $harvest);
    expect(app(OaiPmhHarvestService::class)->page($token->harvest, 0, 1))->toBe([['kind' => 'resource', 'id' => 123]]);
    $harvest->delete();
    expect($token->fresh())->toBeNull()
        ->and(DB::table('oai_pmh_harvest_items')->count())->toBe(0);
});

it('preserves identity order and valid tokens when upgrading and rolling back JSON snapshots', function () {
    $service = app(OaiPmhHarvestService::class);
    $items = array_map(fn (int $position): array => [
        'kind' => $position % 2 === 0 ? 'deleted' : 'resource', 'id' => 500 - $position,
    ], range(0, 12));
    $harvest = OaiPmhHarvest::create(['item_count' => count($items), 'expires_at' => now()->addDay()]);
    $empty = OaiPmhHarvest::create(['item_count' => 0, 'expires_at' => now()->addDay()]);
    DB::table('oai_pmh_harvest_items')->insert(array_map(fn (int $position): array => [
        'harvest_id' => $harvest->id, 'position' => $position,
        'kind' => $items[$position]['kind'], 'identity_id' => $items[$position]['id'],
    ], array_keys($items)));
    $token = app(OaiPmhResumptionTokenService::class)->create('ListIdentifiers', 'oai_dc', 'epos-msl', null, null, 4, 13, $harvest, 5);
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_05_000005_normalize_oai_pmh_harvest_items.php');
    $migration->down();
    try {
        expect(Schema::hasTable('oai_pmh_harvest_items'))->toBeFalse()
            ->and(json_decode(DB::table('oai_pmh_harvests')->where('id', $harvest->id)->value('items'), true))->toEqual($items)
            ->and(json_decode(DB::table('oai_pmh_harvests')->where('id', $empty->id)->value('items'), true))->toBe([])
            ->and($token->fresh()->harvest_position)->toBe(5);
    } finally {
        $migration->up();
    }
    expect(Schema::hasColumn('oai_pmh_harvests', 'items'))->toBeFalse()
        ->and($harvest->fresh()->item_count)->toBe(13)
        ->and($empty->fresh()->item_count)->toBe(0)
        ->and($service->page($harvest, 0, 13))->toBe($items)
        ->and($service->page($harvest, 5, 2))->toBe(array_slice($items, 5, 2))
        ->and(app(OaiPmhResumptionTokenService::class)->resolve($token->token)->id)->toBe($token->id);
    $primary = collect(Schema::getIndexes('oai_pmh_harvest_items'))->firstWhere('primary', true);
    expect($primary['columns'])->toBe(['harvest_id', 'position']);
});

<?php

declare(strict_types=1);

use App\Models\OaiPmhHarvest;
use App\Models\OaiPmhResumptionToken;
use App\Services\OaiPmh\OaiPmhResumptionTokenService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

it('rolls back snapshot storage without losing existing resumption tokens', function () {
    $token = app(OaiPmhResumptionTokenService::class)->create('ListRecords', 'oai_dc', null, null, null, 1, 3);
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
    }
    expect(Schema::hasTable('oai_pmh_harvests'))->toBeTrue()
        ->and($token->fresh()->harvest_id)->toBeNull();
});

it('indexes harvest expiration and cascades tokens when a snapshot is removed', function () {
    $indexes = collect(Schema::getIndexes('oai_pmh_harvests'));
    expect($indexes->contains(fn (array $index): bool => $index['columns'] === ['expires_at']))->toBeTrue();
    $harvest = OaiPmhHarvest::create(['items' => [['kind' => 'resource', 'id' => 123]], 'expires_at' => now()->addDay()]);
    $token = app(OaiPmhResumptionTokenService::class)->create('ListRecords', 'oai_dc', 'epos-msl', null, null, 1, 2, $harvest);
    expect($token->harvest->items)->toEqual([['kind' => 'resource', 'id' => 123]]);
    $harvest->delete();
    expect($token->fresh())->toBeNull();
});

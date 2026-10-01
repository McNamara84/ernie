<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\Resource;
use App\Services\DatacenterNameService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills existing datacenter names and preserves their resource assignments', function (): void {
    $first = Datacenter::factory()->withName('Existing Centre')->create();
    $second = Datacenter::factory()->withName('Other Centre')->create();
    $resource = Resource::factory()->create(['datacenter_id' => $first->id]);

    Schema::drop('datacenter_name_aliases');
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_30_000001_create_datacenter_name_aliases_table.php');
    $migration->up();

    expect(DB::table('datacenter_name_aliases')->count())->toBe(2)
        ->and(DB::table('datacenter_name_aliases')->where('name_key', 'existing centre')->value('datacenter_id'))->toBe($first->id)
        ->and(DB::table('datacenter_name_aliases')->where('name_key', 'other centre')->value('datacenter_id'))->toBe($second->id);

    $names = app(DatacenterNameService::class);
    $names->rename($first, 'Renamed Centre');
    expect($names->find('Existing Centre')?->id)->toBe($first->id)
        ->and($resource->fresh()->datacenter_id)->toBe($first->id)
        ->and($resource->fresh()->datacenter?->name)->toBe('Renamed Centre');

    $migration->down();
    expect(Schema::hasTable('datacenter_name_aliases'))->toBeFalse();
});

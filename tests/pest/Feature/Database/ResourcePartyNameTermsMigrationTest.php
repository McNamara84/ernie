<?php

declare(strict_types=1);

use App\Enums\PortalScope;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceType;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function resourcePartyNameTermsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_23_000001_create_resource_party_name_terms.php');

    return $migration;
}

it('backfills one row per public name term and removes the derived table on rollback', function (): void {
    $type = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => PortalScope::PHYSICAL_SAMPLE_RESOURCE_TYPE,
    ]);
    $resource = Resource::factory()->create(['resource_type_id' => $type->id]);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $person = Person::factory()->create(['given_name' => 'Public', 'family_name' => 'Researcher']);
    ResourceContributor::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'email' => 'private@example.test',
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    $migration = resourcePartyNameTermsMigration();
    $migration->down();

    try {
        expect(Schema::hasTable('resource_party_name_terms'))->toBeFalse();

        $migration->up();
        $terms = DB::table('resource_party_name_terms')
            ->where('resource_id', $resource->id)
            ->pluck('term')
            ->all();

        expect($terms)->toContain('public researcher', 'researcher public')
            ->not->toContain('private@example.test');

        DB::table('resource_party_name_terms')->where('resource_id', $resource->id)->delete();
        app(ResourceListingProjectorService::class)->rebuildAll();
        expect(DB::table('resource_party_name_terms')->where('resource_id', $resource->id)->count())
            ->toBe(count($terms));

        $migration->down();
        expect(Schema::hasTable('resource_party_name_terms'))->toBeFalse();
    } finally {
        if (! Schema::hasTable('resource_party_name_terms')) {
            $migration->up();
        }
    }
});

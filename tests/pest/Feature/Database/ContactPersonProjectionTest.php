<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Services\ContactPersons\ContactPersonFilterService;
use App\Services\ContactPersons\ContactPersonOverviewService;
use App\Services\ContactPersons\ResourceContactProjectorService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has indexed unique contact variants with cascading resource deletion', function (): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create();
    ResourceCreator::factory()->forPerson($person)->create(['resource_id' => $resource->id, 'is_contact' => true, 'email' => 'contact@example.test']);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    expect(Schema::hasIndex('resource_contact_points', 'contact_resource_variant_unique'))->toBeTrue()
        ->and(Schema::hasIndex('resource_contact_points', 'contact_variant_resource_index'))->toBeTrue()
        ->and(Schema::hasIndex('resource_contact_points', 'contact_identity_variant_index'))->toBeTrue();
    $point = (array) DB::table('resource_contact_points')->first();
    unset($point['id']);
    expect(fn () => DB::table('resource_contact_points')->insert($point))->toThrow(QueryException::class);
    // Exercise a bulk deletion that bypasses model events.
    DB::table('resources')->where('id', $resource->id)->delete();
    expect(DB::table('resource_contact_points')->count())->toBe(0);
});

it('preserves case and accent name variants regardless of database collation', function (): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create();
    foreach (['Müller', 'Muller', 'MULLER'] as $name) {
        ResourceCreator::factory()->forPerson($person)->create(['resource_id' => $resource->id, 'is_contact' => true, 'name_snapshot' => $name, 'email' => 'CONTACT@example.test']);
    }
    $data = app(ContactPersonOverviewService::class)->overview('', 25, 1);
    expect($data['groups'])->toHaveCount(1)->and($data['groups'][0]['variants'])->toHaveCount(3);
});

it('loads contact groups with a bounded number of queries as variants grow', function (): void {
    $person = Person::factory()->create();
    $resource = Resource::factory()->create();
    $add = function (int $start, int $end) use ($person, $resource): void {
        for ($i = $start; $i <= $end; $i++) {
            ResourceCreator::factory()->forPerson($person)->create(['resource_id' => $resource->id, 'is_contact' => true, 'email' => "variant{$i}@example.test"]);
        }
        app(ResourceListingProjectionRefreshService::class)->flushPending();
    };
    $measure = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(ContactPersonOverviewService::class)->overview('', 25, 1);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $add(1, 1);
    $small = $measure();
    $add(2, 50);
    expect($measure())->toBe($small)->toBeLessThanOrEqual(5);
});

it('replaces a contact projection from fresh sources instead of an earlier model snapshot', function (): void {
    $resource = Resource::factory()->create();
    $creator = ResourceCreator::factory()->forPerson(Person::factory()->create())->create(['resource_id' => $resource->id, 'is_contact' => true, 'email' => 'old@example.test']);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    DB::table('resource_creators')->where('id', $creator->id)->update(['email' => 'latest@example.test']);
    app(ResourceContactProjectorService::class)->refreshMany([$resource->id]);
    expect(DB::table('resource_contact_points')->value('email'))->toBe('latest@example.test');
});

it('uses the same deterministic email representative in the overview and filter description', function (): void {
    $person = Person::factory()->create();
    foreach (['ada@example.test', 'ADA@example.test'] as $email) {
        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => Resource::factory()->create()->id, 'is_contact' => true, 'email' => $email,
        ]);
    }
    $data = app(ContactPersonOverviewService::class)->overview('', 25, 1);
    $variant = $data['groups'][0]['variants'][0];
    expect($data['groups'][0]['variants'])->toHaveCount(1)
        ->and($variant['resource_count'])->toBe(2)
        ->and($variant['email'])->toBe('ada@example.test')
        ->and(app(ContactPersonFilterService::class)->describe($variant['key'])['email'])->toBe($variant['email']);
});

it('supports earlier listing rebuilds without the contact table and reports a missing migration', function (): void {
    $resource = Resource::factory()->create();
    $migration = require database_path('migrations/2026_10_29_160000_create_resource_contact_points.php');
    $migration->down();
    try {
        app(ResourceContactProjectorService::class)->refreshMany([$resource->id]);
        $this->artisan('contact-persons:rebuild')
            ->expectsOutput('Run database migrations before rebuilding contact persons.')
            ->assertFailed();
    } finally {
        $migration->up();
        Resource::whereKey($resource->id)->delete();
    }
    expect(Schema::hasTable('resource_contact_points'))->toBeTrue();
});

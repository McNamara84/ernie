<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('removes every legacy import endpoint even for privileged users', function (UserRole $role): void {
    $this->actingAs(User::factory()->create(['role' => $role]));
    Queue::fake();
    Http::preventStrayRequests();

    foreach (['datacite', 'igsns'] as $prefix) {
        $this->getJson("/{$prefix}/import/datacenters")->assertNotFound();
        $this->getJson("/{$prefix}/import/retired-run/status")->assertNotFound();
        foreach (['start', 'start-single', 'start-datacenter', 'retired-run/cancel', 'retired-run/retry-sync'] as $action) {
            $this->postJson("/{$prefix}/import/{$action}", [
                'doi' => '10.5880/retired-import', 'datacenter_id' => 'IGSNDB.ICDP',
            ])->assertNotFound();
        }
    }

    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([UserRole::ADMIN, UserRole::GROUP_LEADER]);

it('serves resource and IGSN listings without legacy connections or import capabilities', function (UserRole $role): void {
    $this->withoutVite();
    $this->actingAs(User::factory()->create(['role' => $role]));
    Http::preventStrayRequests();
    foreach (['metaworks', 'legacy_metaworks', 'igsn_legacy'] as $connection) {
        config(["database.connections.{$connection}" => ['driver' => 'retired']]);
    }

    $this->get('/resources')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('resources')->missing('canImportFromDataCite'));
    $this->get('/igsns')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('igsns/index')->missing('canImport'));
    Http::assertNothingSent();
})->with([UserRole::ADMIN, UserRole::GROUP_LEADER]);

it('retires legacy backfills while retaining current maintenance and database exports', function (): void {
    $commands = Artisan::all();
    foreach ([
        'resources:backfill-legacy-temporal-coverages',
        'resources:backfill-legacy-creator-and-msl-metadata',
        'resources:reconcile-legacy-related-identifiers',
        'resources:repair-legacy-description-breaks',
        'landing-pages:backfill-download-labels',
        'igsn:backfill-legacy-dif-metadata',
        'igsn:backfill-classifications',
        'igsn:backfill-descriptions',
        'igsn:backfill-images',
    ] as $name) {
        expect($commands)->not->toHaveKey($name);
    }

    expect($commands)->toHaveKeys(['related-identifiers:hydrate-citation-labels', 'igsn:audit-legacy-handles'])
        ->and(config('database_dumps.targets'))->toHaveKeys(['ernie', 'sumariopmd', 'metaworks', 'igsn'])
        ->and(config('database_dumps.targets.igsn.dump_binary'))->toContain('mysql-legacy-mysqldump');
});

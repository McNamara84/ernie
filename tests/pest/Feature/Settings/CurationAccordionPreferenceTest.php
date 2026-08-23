<?php

declare(strict_types=1);

use App\Http\Requests\Settings\UpdateCurationAccordionRequest;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function loadCurationAccordionOpenItemsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_06_05_000001_add_curation_accordion_open_items_to_users_table.php');

    return $migration;
}

function loadCurationAccordionRevisionMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_08_23_000001_add_curation_accordion_revision_to_users_table.php');

    return $migration;
}

test('allowed curation accordion item values stay in sync with frontend constants', function () {
    $frontendConstants = (string) file_get_contents(resource_path('js/lib/curation-accordion.ts'));

    preg_match('/export const CURATION_ACCORDION_ITEM_VALUES = \[(?<items>.*?)\] as const;/s', $frontendConstants, $matches);
    preg_match_all("/'([^']+)'/", $matches['items'] ?? '', $itemMatches);

    expect(UpdateCurationAccordionRequest::ALLOWED_OPEN_ITEMS)->toEqualCanonicalizing($itemMatches[1]);
});

test('guests are redirected when updating curation accordion preference', function () {
    $this->put(route('curation-accordion.update'), [
        'open_items' => ['authors'],
        'revision' => 1,
    ])->assertRedirect(route('login'));
});

test('unauthenticated background preference updates are rejected', function () {
    $this->putJson(route('curation-accordion.update'), [
        'open_items' => ['authors'],
        'revision' => 1,
    ])->assertUnauthorized();
});

test('authenticated users can persist curation accordion open items', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'open_items' => ['authors', 'funding-references'],
            'revision' => 100,
        ])
        ->assertNoContent()
        ->assertHeader('X-Curation-Accordion-Revision', '100');

    $user->refresh();

    expect($user->curation_accordion_open_items)->toBe([
        'authors',
        'funding-references',
    ])->and($user->curation_accordion_revision)->toBe(100);
});

test('authenticated users can persist all curation accordions as collapsed', function () {
    $user = User::factory()->create([
        'curation_accordion_open_items' => ['authors'],
    ]);

    $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'open_items' => [],
            'revision' => 101,
        ])
        ->assertNoContent();

    $user->refresh();

    expect($user->curation_accordion_open_items)->toBe([])
        ->and($user->curation_accordion_revision)->toBe(101);
});

test('omitting open items persists the default collapsed preference', function () {
    $user = User::factory()->create([
        'curation_accordion_open_items' => ['authors'],
    ]);

    $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'revision' => 102,
        ])
        ->assertNoContent();

    $user->refresh();

    expect($user->curation_accordion_open_items)->toBe([])
        ->and($user->curation_accordion_revision)->toBe(102);
});

test('a revision is required without changing the preference', function () {
    $user = User::factory()->create([
        'curation_accordion_open_items' => ['authors'],
    ]);

    $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'open_items' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('revision');

    $user->refresh();

    expect($user->curation_accordion_open_items)->toBe(['authors'])
        ->and($user->curation_accordion_revision)->toBeNull();
});

test('non-array open items are rejected without changing the preference', function () {
    $user = User::factory()->create([
        'curation_accordion_open_items' => ['authors'],
    ]);

    $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'open_items' => 'authors',
            'revision' => 103,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('open_items');

    expect($user->refresh()->curation_accordion_open_items)->toBe(['authors']);
});

test('unknown curation accordion item values are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'open_items' => ['authors', 'unknown-section'],
            'revision' => 104,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('open_items.1');

    expect($user->refresh()->curation_accordion_open_items)->toBeNull();
});

test('legacy resource information values are discarded while valid accordion items are persisted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'open_items' => ['resource-info', 'authors'],
            'revision' => 105,
        ])
        ->assertNoContent();

    expect($user->refresh()->curation_accordion_open_items)->toBe(['authors']);
});

test('duplicate curation accordion item values are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'open_items' => ['authors', 'authors'],
            'revision' => 106,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('open_items.1');

    expect($user->refresh()->curation_accordion_open_items)->toBeNull();
});

test('a stale request from another editor tab cannot overwrite a newer preference', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'open_items' => ['funding-references'],
            'revision' => 200,
        ])
        ->assertNoContent();

    $staleResponse = $this->actingAs($user)
        ->putJson(route('curation-accordion.update'), [
            'open_items' => ['authors'],
            'revision' => 199,
        ]);

    $staleResponse
        ->assertNoContent()
        ->assertHeader('X-Curation-Accordion-Revision', '200');

    $user->refresh();

    expect($user->curation_accordion_open_items)->toBe(['funding-references'])
        ->and($user->curation_accordion_revision)->toBe(200);
});

test('curation accordion open items migration can be rerun safely', function () {
    $migration = loadCurationAccordionOpenItemsMigration();

    expect(Schema::hasColumn('users', 'curation_accordion_open_items'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->up();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('users', 'curation_accordion_open_items'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasColumn('users', 'curation_accordion_open_items'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('users', 'curation_accordion_open_items'))->toBeTrue();
});

test('curation accordion revision migration can be rerun safely', function () {
    $migration = loadCurationAccordionRevisionMigration();

    expect(Schema::hasColumn('users', 'curation_accordion_revision'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->up();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('users', 'curation_accordion_revision'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasColumn('users', 'curation_accordion_revision'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('users', 'curation_accordion_revision'))->toBeTrue();
});

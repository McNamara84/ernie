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

test('allowed curation accordion item values stay in sync with frontend constants', function () {
    $frontendConstants = (string) file_get_contents(resource_path('js/lib/curation-accordion.ts'));

    preg_match('/export const CURATION_ACCORDION_ITEM_VALUES = \[(?<items>.*?)\] as const;/s', $frontendConstants, $matches);
    preg_match_all("/'([^']+)'/", $matches['items'] ?? '', $itemMatches);

    expect(UpdateCurationAccordionRequest::ALLOWED_OPEN_ITEMS)->toEqualCanonicalizing($itemMatches[1]);
});

test('guests are redirected when updating curation accordion preference', function () {
    $this->put(route('curation-accordion.update'), [
        'open_items' => ['resource-info'],
    ])->assertRedirect(route('login'));
});

test('authenticated users can persist curation accordion open items', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('curation-accordion.update'), [
            'open_items' => ['resource-info', 'authors', 'funding-references'],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->curation_accordion_open_items)->toBe([
        'resource-info',
        'authors',
        'funding-references',
    ]);
});

test('authenticated users can persist all curation accordions as collapsed', function () {
    $user = User::factory()->create([
        'curation_accordion_open_items' => ['resource-info'],
    ]);

    $this->actingAs($user)
        ->put(route('curation-accordion.update'), [
            'open_items' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->curation_accordion_open_items)->toBe([]);
});

test('unknown curation accordion item values are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/editor')
        ->put(route('curation-accordion.update'), [
            'open_items' => ['resource-info', 'unknown-section'],
        ])
        ->assertRedirect('/editor')
        ->assertSessionHasErrors('open_items.1');

    expect($user->refresh()->curation_accordion_open_items)->toBeNull();
});

test('duplicate curation accordion item values are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/editor')
        ->put(route('curation-accordion.update'), [
            'open_items' => ['authors', 'authors'],
        ])
        ->assertRedirect('/editor')
        ->assertSessionHasErrors('open_items.1');
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

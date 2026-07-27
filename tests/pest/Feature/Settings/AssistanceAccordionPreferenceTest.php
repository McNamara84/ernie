<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function loadAssistanceCollapsedAssistantIdsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_07_27_000001_add_assistance_collapsed_assistant_ids_to_users_table.php');

    return $migration;
}

/** @return list<string> */
function registeredAssistantIds(): array
{
    /** @var AssistantRegistrar $registrar */
    $registrar = app(AssistantRegistrar::class);

    return array_keys($registrar->getAll());
}

test('guests are redirected when updating the Assistance accordion preference', function () {
    $this->put(route('assistance-accordion.update'), [
        'collapsed_assistant_ids' => [registeredAssistantIds()[0]],
    ])->assertRedirect(route('login'));
});

test('authenticated users can persist collapsed Assistant IDs', function () {
    $user = User::factory()->create();
    $assistantIds = array_slice(registeredAssistantIds(), 0, 2);

    $this->actingAs($user)
        ->put(route('assistance-accordion.update'), [
            'collapsed_assistant_ids' => $assistantIds,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->assistance_collapsed_assistant_ids)->toBe($assistantIds);
});

test('authenticated users can persist all Assistants as expanded', function () {
    $user = User::factory()->create([
        'assistance_collapsed_assistant_ids' => [registeredAssistantIds()[0]],
    ]);

    $this->actingAs($user)
        ->put(route('assistance-accordion.update'), [
            'collapsed_assistant_ids' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->assistance_collapsed_assistant_ids)->toBe([]);
});

test('omitting collapsed Assistant IDs persists the expanded default', function () {
    $user = User::factory()->create([
        'assistance_collapsed_assistant_ids' => [registeredAssistantIds()[0]],
    ]);

    $this->actingAs($user)
        ->put(route('assistance-accordion.update'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->assistance_collapsed_assistant_ids)->toBe([]);
});

test('non-array collapsed Assistant IDs are rejected without changing the preference', function () {
    $assistantId = registeredAssistantIds()[0];
    $user = User::factory()->create([
        'assistance_collapsed_assistant_ids' => [$assistantId],
    ]);

    $this->actingAs($user)
        ->from('/assistance')
        ->put(route('assistance-accordion.update'), [
            'collapsed_assistant_ids' => $assistantId,
        ])
        ->assertRedirect('/assistance')
        ->assertSessionHasErrors('collapsed_assistant_ids');

    expect($user->refresh()->assistance_collapsed_assistant_ids)->toBe([$assistantId]);
});

test('unknown Assistant IDs are rejected without changing the preference', function () {
    $assistantId = registeredAssistantIds()[0];
    $user = User::factory()->create([
        'assistance_collapsed_assistant_ids' => [$assistantId],
    ]);

    $this->actingAs($user)
        ->from('/assistance')
        ->put(route('assistance-accordion.update'), [
            'collapsed_assistant_ids' => [$assistantId, 'removed-assistant'],
        ])
        ->assertRedirect('/assistance')
        ->assertSessionHasErrors('collapsed_assistant_ids.1');

    expect($user->refresh()->assistance_collapsed_assistant_ids)->toBe([$assistantId]);
});

test('duplicate Assistant IDs are rejected without changing the preference', function () {
    $assistantId = registeredAssistantIds()[0];
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/assistance')
        ->put(route('assistance-accordion.update'), [
            'collapsed_assistant_ids' => [$assistantId, $assistantId],
        ])
        ->assertRedirect('/assistance')
        ->assertSessionHasErrors('collapsed_assistant_ids.1');

    expect($user->refresh()->assistance_collapsed_assistant_ids)->toBeNull();
});

test('Assistance accordion preferences are isolated per user', function () {
    [$firstAssistantId, $secondAssistantId] = array_slice(registeredAssistantIds(), 0, 2);
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create([
        'assistance_collapsed_assistant_ids' => [$secondAssistantId],
    ]);

    $this->actingAs($firstUser)
        ->put(route('assistance-accordion.update'), [
            'collapsed_assistant_ids' => [$firstAssistantId],
        ])
        ->assertSessionHasNoErrors();

    expect($firstUser->refresh()->assistance_collapsed_assistant_ids)->toBe([$firstAssistantId])
        ->and($secondUser->refresh()->assistance_collapsed_assistant_ids)->toBe([$secondAssistantId]);
});

test('Assistance accordion migration can be rerun safely', function () {
    $migration = loadAssistanceCollapsedAssistantIdsMigration();

    expect(Schema::hasColumn('users', 'assistance_collapsed_assistant_ids'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->up();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('users', 'assistance_collapsed_assistant_ids'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasColumn('users', 'assistance_collapsed_assistant_ids'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('users', 'assistance_collapsed_assistant_ids'))->toBeTrue();
});

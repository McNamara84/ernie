<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use App\Services\Curation\CurationService;
use Inertia\Testing\AssertableInertia as Assert;

it('denies beginners the colleague lookup regardless of query parameters', function (string $query): void {
    $colleague = User::factory()->curator()->create(['email' => 'colleague@example.test']);

    $this->actingAs(User::factory()->beginner()->create())
        ->getJson('/curation/users'.$query)
        ->assertForbidden()
        ->assertDontSee($colleague->email);
})->with(['', '?mentions=0', '?mentions=1', '?mentions=1&q=colleague']);

it('allows active curation writers to find eligible colleagues by name and email', function (UserRole $role): void {
    $actor = User::factory()->create(['role' => $role]);
    $curator = User::factory()->curator()->create(['name' => 'Lookup Colleague', 'email' => 'curator@example.test']);
    $beginner = User::factory()->beginner()->create(['name' => 'Lookup Colleague', 'email' => 'beginner@example.test']);
    User::factory()->curator()->create(['name' => 'Lookup Colleague', 'is_active' => false]);
    $this->actingAs($actor);

    $this->getJson('/curation/users?q=Lookup')->assertOk()->assertExactJson([
        ['id' => $curator->id, 'name' => $curator->name, 'email' => $curator->email],
    ]);
    $this->getJson('/curation/users?mentions=0&q=Lookup')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $curator->id);
    $this->getJson('/curation/users?mentions=1&q=Lookup')->assertOk()->assertExactJson([
        ['id' => $curator->id, 'name' => $curator->name, 'email' => $curator->email],
        ['id' => $beginner->id, 'name' => $beginner->name, 'email' => $beginner->email],
    ]);
    $this->getJson('/curation/users?mentions=1&q=beginner%40example.test')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $beginner->id);
})->with([UserRole::CURATOR, UserRole::GROUP_LEADER, UserRole::ADMIN]);

it('denies inactive users the colleague lookup in every mode', function (UserRole $role): void {
    $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => false]));
    foreach (['', '?mentions=0', '?mentions=1'] as $query) {
        $this->getJson('/curation/users'.$query)->assertForbidden();
    }
})->with(UserRole::cases());

it('requires authentication for the colleague lookup', function (): void {
    foreach (['', '?mentions=0', '?mentions=1'] as $query) {
        $this->getJson('/curation/users'.$query)->assertUnauthorized();
    }
});

it('preserves beginner access to the overview export checklist and history', function (): void {
    $beginner = User::factory()->beginner()->create();
    $resource = Resource::factory()->create();
    app(CurationService::class)->start($resource, $beginner);
    $this->actingAs($beginner);

    $this->get('/curation')->assertOk()->assertInertia(fn (Assert $page) => $page->component('curation')->where('resources.total', 1));
    $this->get('/curation/export')->assertOk();
    $this->getJson('/resources/'.$resource->id.'/curation')->assertOk()->assertJsonPath('can_write', false)->assertJsonCount(13, 'items');
    $this->getJson('/resources/'.$resource->id.'/curation/events')->assertOk();
});

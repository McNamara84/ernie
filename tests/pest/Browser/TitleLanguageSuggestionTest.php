<?php

declare(strict_types=1);

namespace Tests\Pest\Browser;

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Title;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepts title language suggestion and updates the title language', function () {
    $this->seed(LanguageSeeder::class);

    $user = User::factory()
        ->asGroupLeader()
        ->create();

    $resource = Resource::factory()
        ->ownedBy($user)
        ->create();

    $title = Title::factory()
        ->for($resource)
        ->create(['language' => null]);

    $suggestion = AssistantSuggestion::create([
        'assistant_id' => 'title-language-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'title',
        'target_id' => $title->id,
        'suggested_value' => 'en',
        'suggested_label' => 'English',
        'similarity_score' => 0.95,
        'metadata' => ['confidence' => 95],
        'discovered_at' => now(),
    ]);

    visit('/assistance')
        ->asUser($user)
        ->assertSee('English')
        ->assertSee($title->value)
        ->click('button:has-text("Accept")')
        ->waitForNavigation();

    expect($title->fresh()->language)->toBe('en');
    expect(AssistantSuggestion::where('id', $suggestion->id)->exists())->toBeFalse();

    visit('/assistance')
        ->asUser($user)
        ->assertDontSee($title->value)
        ->assertDontSee('English');
});
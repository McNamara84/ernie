<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Title;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class)->group('title-language', 'browser');

describe('Title Language Detection assistant', function (): void {
    it('loads the assistance page with a pending title language suggestion without smoke errors', function (): void {
        /** @var TestCase $this */
        $this->seed(LanguageSeeder::class);

        $user = User::factory()
            ->groupLeader()
            ->create();

        $resource = Resource::factory()->create();

        $title = Title::factory()
            ->for($resource)
            ->create([
                'language' => null,
                'value' => 'Groundwater Recharge',
            ]);

        AssistantSuggestion::create([
            'assistant_id' => 'title-language-suggestion',
            'resource_id' => $resource->id,
            'target_type' => 'title',
            'target_id' => $title->id,
            'suggested_value' => 'en',
            'suggested_label' => 'English (en) · 95% confidence · current: not set · "Groundwater Recharge"',
            'similarity_score' => 0.95,
            'metadata' => [
                'title_text' => $title->value,
                'current_language' => null,
                'current_language_label' => null,
                'proposed_language' => 'en',
                'proposed_language_label' => 'English',
                'confidence' => 0.95,
                'confidence_percent' => 95,
                'reason' => 'Detected from title text using ELD language detection. Only German, English and French suggestions are supported.',
                'warning' => null,
                'has_overwrite_warning' => false,
                'is_stale' => false,
            ],
            'discovered_at' => now(),
        ]);

        $this->actingAs($user);

        visit('/assistance')
            ->assertNoSmoke();

        expect(
            AssistantSuggestion::where('assistant_id', 'title-language-suggestion')
                ->where('target_type', 'title')
                ->where('target_id', $title->id)
                ->exists()
        )->toBeTrue();
    });

    it('accepts a title language suggestion and updates the title language', function (): void {
        /** @var TestCase $this */
        $this->seed(LanguageSeeder::class);

        $user = User::factory()
            ->groupLeader()
            ->create();

        $resource = Resource::factory()->create();

        $title = Title::factory()
            ->for($resource)
            ->create([
                'language' => null,
                'value' => 'Groundwater Recharge',
            ]);

        $suggestion = AssistantSuggestion::create([
            'assistant_id' => 'title-language-suggestion',
            'resource_id' => $resource->id,
            'target_type' => 'title',
            'target_id' => $title->id,
            'suggested_value' => 'en',
            'suggested_label' => 'English (en) · 95% confidence · current: not set · "Groundwater Recharge"',
            'similarity_score' => 0.95,
            'metadata' => [
                'title_text' => $title->value,
                'current_language' => null,
                'current_language_label' => null,
                'proposed_language' => 'en',
                'proposed_language_label' => 'English',
                'confidence' => 0.95,
                'confidence_percent' => 95,
                'reason' => 'Detected from title text using ELD language detection. Only German, English and French suggestions are supported.',
                'warning' => null,
                'has_overwrite_warning' => false,
                'is_stale' => false,
            ],
            'discovered_at' => now(),
        ]);

        $this->actingAs($user);

        visit('/assistance')
            ->assertNoSmoke();

        $response = $this->actingAs($user)
            ->post("/assistance/title-language/{$suggestion->id}/accept");

        $response->assertOk();

        expect($title->fresh()->language)->toBe('en');

        expect(
            AssistantSuggestion::where('id', $suggestion->id)->exists()
        )->toBeFalse();

        visit('/assistance')
            ->assertNoSmoke();
    });

    it('does not recreate an accepted title language suggestion for a title that now has a language', function (): void {
        /** @var TestCase $this */
        $this->seed(LanguageSeeder::class);

        $user = User::factory()
            ->groupLeader()
            ->create();

        $resource = Resource::factory()->create();

        $title = Title::factory()
            ->for($resource)
            ->create([
                'language' => null,
                'value' => 'Groundwater Recharge',
            ]);

        $suggestion = AssistantSuggestion::create([
            'assistant_id' => 'title-language-suggestion',
            'resource_id' => $resource->id,
            'target_type' => 'title',
            'target_id' => $title->id,
            'suggested_value' => 'en',
            'suggested_label' => 'English (en) · 95% confidence · current: not set · "Groundwater Recharge"',
            'similarity_score' => 0.95,
            'metadata' => [
                'title_text' => $title->value,
                'current_language' => null,
                'current_language_label' => null,
                'proposed_language' => 'en',
                'proposed_language_label' => 'English',
                'confidence' => 0.95,
                'confidence_percent' => 95,
                'reason' => 'Detected from title text using ELD language detection. Only German, English and French suggestions are supported.',
                'warning' => null,
                'has_overwrite_warning' => false,
                'is_stale' => false,
            ],
            'discovered_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->post("/assistance/title-language/{$suggestion->id}/accept");

        $response->assertOk();

        expect($title->fresh()->language)->toBe('en');
        expect(AssistantSuggestion::where('id', $suggestion->id)->exists())->toBeFalse();

        expect(
            AssistantSuggestion::where('assistant_id', 'title-language-suggestion')
                ->where('target_type', 'title')
                ->where('target_id', $title->id)
                ->exists()
        )->toBeFalse();
    });
});
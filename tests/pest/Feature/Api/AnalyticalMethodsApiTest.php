<?php

declare(strict_types=1);

use App\Models\ThesaurusSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_ANALYTICAL_METHODS],
        [
            'display_name' => 'Analytical Methods for Geochemistry',
            'is_active' => true,
            'is_elmo_active' => true,
            'version' => '1-4',
        ],
    );
});

describe('VocabularyController - Analytical Methods', function () {
    it('returns analytical methods vocabulary when active', function () {
        Storage::fake('local');
        Storage::put('analytical-methods.json', json_encode([
            'lastUpdated' => '2025-01-01 00:00:00',
            'data' => [
                [
                    'id' => 'https://w3id.org/geochem/1.0/analyticalmethod/spectrometry',
                    'text' => 'Spectrometry',
                    'notation' => 'SPEC',
                    'children' => [],
                ],
            ],
        ]));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/vocabularies/analytical-methods')
            ->assertOk()
            ->assertJsonPath('data.0.text', 'Spectrometry')
            ->assertJsonPath('data.0.notation', 'SPEC');
    });

    it('returns 404 when analytical methods is disabled', function () {
        Storage::fake('local');
        ThesaurusSetting::where('type', ThesaurusSetting::TYPE_ANALYTICAL_METHODS)
            ->update(['is_active' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/vocabularies/analytical-methods')
            ->assertNotFound()
            ->assertJsonFragment(['error' => 'Thesaurus is disabled']);
    });

    it('returns 404 when vocabulary file does not exist', function () {
        Storage::fake('local');
        Cache::flush();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/vocabularies/analytical-methods')
            ->assertNotFound();
    });
});

describe('Thesauri Availability', function () {
    it('includes analytical_methods in thesauri availability response', function () {
        $this->getJson('/api/v1/vocabularies/thesauri-availability')
            ->assertOk()
            ->assertJsonPath('analytical_methods.available', true)
            ->assertJsonPath('analytical_methods.displayName', 'Analytical Methods for Geochemistry');
    });

    it('reflects disabled state in availability response', function () {
        ThesaurusSetting::where('type', ThesaurusSetting::TYPE_ANALYTICAL_METHODS)
            ->update(['is_active' => false]);

        $this->getJson('/api/v1/vocabularies/thesauri-availability')
            ->assertOk()
            ->assertJsonPath('analytical_methods.available', false);
    });
});

describe('ThesaurusSettingsController - Analytical Methods', function () {
    it('includes version in thesaurus listing', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->getJson('/thesauri')
            ->assertOk();

        $analyticalMethods = collect($response->json())->firstWhere('type', 'analytical_methods');

        expect($analyticalMethods)->not->toBeNull()
            ->and($analyticalMethods['version'])->toBe('1-4')
            ->and($analyticalMethods['displayName'])->toBe('Analytical Methods for Geochemistry');
    });

    it('allows admin to update version', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson('/thesauri/analytical_methods/version', [
                'version' => '2-0',
            ])
            ->assertOk()
            ->assertJsonFragment([
                'version' => '2-0',
                'message' => 'Version updated successfully. Please trigger a vocabulary update to fetch the new version.',
            ]);

        expect(ThesaurusSetting::where('type', 'analytical_methods')->first()->version)
            ->toBe('2-0');
    });

    it('invalidates cache and deletes vocabulary file on version change', function () {
        Storage::fake('local');
        Storage::put('analytical-methods.json', json_encode(['data' => []]));

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson('/thesauri/analytical_methods/version', [
                'version' => '2-0',
            ])
            ->assertOk();

        Storage::assertMissing('analytical-methods.json');
    });

    it('rejects version update for non-versioned thesauri', function () {
        ThesaurusSetting::updateOrCreate(
            ['type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS],
            [
                'display_name' => 'Science Keywords',
                'is_active' => true,
                'is_elmo_active' => true,
            ],
        );

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson('/thesauri/science_keywords/version', [
                'version' => '1-0',
            ])
            ->assertStatus(400)
            ->assertJsonFragment(['error' => 'This thesaurus does not support versioning.']);
    });

    it('validates version format', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson('/thesauri/analytical_methods/version', [
                'version' => 'invalid version!@#',
            ])
            ->assertUnprocessable();
    });

    it('prevents curators from updating version', function () {
        $curator = User::factory()->create(['role' => \App\Enums\UserRole::CURATOR]);

        $this->actingAs($curator)
            ->patchJson('/thesauri/analytical_methods/version', [
                'version' => '2-0',
            ])
            ->assertForbidden();
    });

    it('allows group leaders to update version', function () {
        \App\Models\ThesaurusSetting::updateOrCreate(
            ['type' => 'analytical_methods'],
            ['version' => '1-4', 'display_name' => 'Analytical Methods', 'is_active' => true, 'is_elmo_active' => true],
        );

        $groupLeader = User::factory()->create(['role' => \App\Enums\UserRole::GROUP_LEADER]);

        $this->actingAs($groupLeader)
            ->patchJson('/thesauri/analytical_methods/version', [
                'version' => '2-0',
            ])
            ->assertOk()
            ->assertJsonFragment(['version' => '2-0']);
    });

    it('returns 404 for unknown thesaurus type', function () {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson('/thesauri/nonexistent_type/version', [
                'version' => '1-0',
            ])
            ->assertNotFound()
            ->assertJsonFragment(['error' => "Thesaurus type 'nonexistent_type' not found"]);
    });
});

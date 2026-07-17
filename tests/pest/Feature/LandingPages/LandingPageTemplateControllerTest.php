<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Http\Controllers\LandingPageTemplateController;
use App\Http\Requests\StoreLandingPageTemplateRequest;
use App\Http\Requests\UpdateLandingPageTemplateRequest;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\User;
use App\Policies\LandingPageTemplatePolicy;
use Database\Factories\LandingPageTemplateFactory;
use Database\Seeders\LandingPageTemplateSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

covers(
    LandingPageTemplateController::class,
    LandingPageTemplate::class,
    LandingPageTemplatePolicy::class,
    StoreLandingPageTemplateRequest::class,
    UpdateLandingPageTemplateRequest::class,
    LandingPageTemplateFactory::class,
    LandingPageTemplateSeeder::class,
);

uses()->group('landing-page-templates');

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->groupLeader = User::factory()->groupLeader()->create();
    $this->curator = User::factory()->curator()->create();
    $this->beginner = User::factory()->beginner()->create();

    // Ensure both system-owned default templates exist using production self-heal logic.
    $systemTemplates = LandingPageTemplate::ensureSystemTemplatesExist();
    $this->defaultTemplate = $systemTemplates[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE];
    $this->igsnDefaultTemplate = $systemTemplates[LandingPageTemplate::TEMPLATE_TYPE_IGSN];
});

function locationFirstRightColumnOrder(): array
{
    return [
        'location',
        ...array_values(array_filter(
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
            static fn (string $key): bool => $key !== 'location',
        )),
    ];
}

// ─── Authorization ───────────────────────────────────────────────────────────

describe('Authorization', function (): void {
    it('allows admins to view template index', function (): void {
        $this->actingAs($this->admin)
            ->get('/landing-pages')
            ->assertOk();
    });

    it('allows group leaders to view template index', function (): void {
        $this->actingAs($this->groupLeader)
            ->get('/landing-pages')
            ->assertOk();
    });

    it('denies curators access to template index', function (): void {
        $this->actingAs($this->curator)
            ->get('/landing-pages')
            ->assertForbidden();
    });

    it('denies beginners access to template index', function (): void {
        $this->actingAs($this->beginner)
            ->get('/landing-pages')
            ->assertForbidden();
    });

    it('denies curator from creating templates', function (): void {
        $this->actingAs($this->curator)
            ->postJson('/landing-pages', ['name' => 'Test'])
            ->assertForbidden();
    });

    it('denies curator from updating templates', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->curator)
            ->putJson("/landing-pages/{$template->id}", ['name' => 'Updated'])
            ->assertForbidden();
    });

    it('denies curator from deleting templates', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->curator)
            ->deleteJson("/landing-pages/{$template->id}")
            ->assertForbidden();
    });
});

// ─── Index Page ──────────────────────────────────────────────────────────────

describe('Index', function (): void {
    it('returns template list via Inertia', function (): void {
        LandingPageTemplate::factory()->count(2)->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->get('/landing-pages')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('landing-page-templates')
                ->has('templates', 4) // resource default + IGSN default + 2 custom
            );
    });

    it('orders defaults first and keeps resource templates ahead of igsn templates', function (): void {
        LandingPageTemplate::factory()->create([
            'name' => 'Zulu Resource Clone',
            'template_type' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
            'created_by' => $this->admin->id,
        ]);

        LandingPageTemplate::factory()->create([
            'name' => 'Alpha Resource Clone',
            'template_type' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
            'created_by' => $this->admin->id,
        ]);

        LandingPageTemplate::factory()->igsn()->create([
            'name' => 'Zulu IGSN Clone',
            'created_by' => $this->admin->id,
        ]);

        LandingPageTemplate::factory()->igsn()->create([
            'name' => 'Alpha IGSN Clone',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/landing-pages')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('templates.0.name', LandingPageTemplate::DEFAULT_TEMPLATE_NAME)
                ->where('templates.1.name', LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_NAME)
                ->where('templates.2.name', 'Alpha Resource Clone')
                ->where('templates.3.name', 'Zulu Resource Clone')
                ->where('templates.4.name', 'Alpha IGSN Clone')
                ->where('templates.5.name', 'Zulu IGSN Clone')
            );
    });
});

// ─── Clone (Store) ───────────────────────────────────────────────────────────

describe('Clone', function (): void {
    it('clones the default template with a new name', function (): void {
        $response = $this->actingAs($this->admin)
            ->postJson('/landing-pages', ['name' => 'My Custom Template']);

        $response->assertCreated();

        $template = LandingPageTemplate::where('name', 'My Custom Template')->first();

        expect($template)->not->toBeNull()
            ->and($template->is_default)->toBeFalse()
            ->and($template->created_by)->toBe($this->admin->id)
            ->and($template->right_column_order)->toBe(LandingPageTemplate::RIGHT_COLUMN_SECTIONS)
            ->and($template->left_column_order)->toBe(LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS)
            ->and($template->creator_display_limit)->toBe(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT)
            ->and($template->contributor_display_limit)->toBe(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT)
            ->and($template->citation_author_display_limit)->toBe(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT);
    });

    it('copies display limits from the selected default template when cloning', function (): void {
        $this->defaultTemplate->update([
            'creator_display_limit' => 25,
            'contributor_display_limit' => 75,
            'citation_author_display_limit' => 11,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/landing-pages', ['name' => 'Limited Clone']);

        $response->assertCreated();

        $template = LandingPageTemplate::where('name', 'Limited Clone')->first();

        expect($template)->not->toBeNull()
            ->and($template?->creator_display_limit)->toBe(25)
            ->and($template?->contributor_display_limit)->toBe(75)
            ->and($template?->citation_author_display_limit)->toBe(11);
    });

    it('rejects duplicate names', function (): void {
        LandingPageTemplate::factory()->create([
            'name' => 'Existing Template',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/landing-pages', ['name' => 'Existing Template'])
            ->assertJsonValidationErrors(['name']);
    });

    it('rejects empty name', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/landing-pages', ['name' => ''])
            ->assertJsonValidationErrors(['name']);
    });

    it('generates a unique slug', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/landing-pages', ['name' => 'My Template']);

        $template = LandingPageTemplate::where('name', 'My Template')->first();

        expect($template->slug)->toStartWith('my-template');
    });

    it('self-heals when the default template is missing and still clones successfully', function (): void {
        LandingPageTemplate::query()->delete();

        $response = $this->actingAs($this->admin)
            ->postJson('/landing-pages', ['name' => 'Template Without Default']);

        $response->assertCreated();

        $clonedTemplate = LandingPageTemplate::where('name', 'Template Without Default')->first();
        $restoredDefaultTemplate = LandingPageTemplate::where('slug', LandingPageTemplate::DEFAULT_TEMPLATE_SLUG)->first();

        expect($clonedTemplate)->not->toBeNull()
            ->and($clonedTemplate?->is_default)->toBeFalse()
            ->and($restoredDefaultTemplate)->not->toBeNull()
            ->and($restoredDefaultTemplate?->is_default)->toBeTrue();
    });

    it('restores is_default=true when default template slug row exists but is not marked as default', function (): void {
        LandingPageTemplate::query()->delete();

        $staleDefault = LandingPageTemplate::factory()->create([
            'slug' => LandingPageTemplate::DEFAULT_TEMPLATE_SLUG,
            'name' => 'Stale Default Template',
            'is_default' => false,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/landing-pages', ['name' => 'Recovered Clone'])
            ->assertCreated();

        expect($staleDefault->fresh()->is_default)->toBeTrue();
    });

    it('restores default template even when preferred default name is already taken', function (): void {
        LandingPageTemplate::query()->delete();

        LandingPageTemplate::factory()->create([
            'slug' => 'custom-template-slug',
            'name' => LandingPageTemplate::DEFAULT_TEMPLATE_NAME,
            'is_default' => false,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/landing-pages', ['name' => 'Clone With Name Collision'])
            ->assertCreated();

        $restoredDefault = LandingPageTemplate::query()
            ->where('slug', LandingPageTemplate::DEFAULT_TEMPLATE_SLUG)
            ->first();

        expect($restoredDefault)->not->toBeNull()
            ->and($restoredDefault?->is_default)->toBeTrue()
            ->and($restoredDefault?->name)->not->toBe(LandingPageTemplate::DEFAULT_TEMPLATE_NAME)
            ->and($restoredDefault?->name)->toStartWith(LandingPageTemplate::DEFAULT_TEMPLATE_NAME);
    });

    it('creates canonical default slug and demotes other default rows', function (): void {
        LandingPageTemplate::query()->delete();

        $wrongDefault = LandingPageTemplate::factory()->create([
            'slug' => 'custom-default-candidate',
            'name' => 'Wrong Default Candidate',
            'is_default' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/landing-pages', ['name' => 'Clone After Wrong Default'])
            ->assertCreated();

        $canonicalDefault = LandingPageTemplate::query()
            ->where('slug', LandingPageTemplate::DEFAULT_TEMPLATE_SLUG)
            ->first();

        expect($canonicalDefault)->not->toBeNull()
            ->and($canonicalDefault?->is_default)->toBeTrue()
            ->and($wrongDefault->fresh()->is_default)->toBeFalse();
    });

    it('clones the IGSN default template when template_type=igsn is provided', function (): void {
        $response = $this->actingAs($this->admin)
            ->postJson('/landing-pages', [
                'name' => 'My IGSN Template',
                'template_type' => 'igsn',
            ]);

        $response->assertCreated();

        $template = LandingPageTemplate::where('name', 'My IGSN Template')->first();

        expect($template)->not->toBeNull()
            ->and($template?->is_default)->toBeFalse()
            ->and($template?->template_type)->toBe(LandingPageTemplate::TEMPLATE_TYPE_IGSN)
            ->and($template?->left_column_order)->toBe(LandingPageTemplate::IGSN_LEFT_COLUMN_SECTIONS);
    });

    it('rejects invalid template_type values', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/landing-pages', [
                'name' => 'Bad Type',
                'template_type' => 'unknown',
            ])
            ->assertJsonValidationErrors(['template_type']);
    });

    it('does not demote IGSN default when restoring resource default', function (): void {
        // Both system templates already exist via beforeEach.
        $igsnDefaultId = $this->igsnDefaultTemplate->id;

        // Trigger restore of resource default by demoting it manually.
        LandingPageTemplate::query()
            ->where('id', $this->defaultTemplate->id)
            ->update(['is_default' => false]);

        LandingPageTemplate::ensureDefaultTemplateExists();

        expect($this->defaultTemplate->fresh()?->is_default)->toBeTrue()
            ->and(LandingPageTemplate::find($igsnDefaultId)?->is_default)->toBeTrue();
    });
});

// ─── Update ──────────────────────────────────────────────────────────────────

describe('Update', function (): void {
    it('updates template name and section order', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $newRightOrder = locationFirstRightColumnOrder();
        $newLeftOrder = ['contact', 'files', 'citation', 'dates', 'related_work', 'model_description'];

        $response = $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'name' => 'Updated Name',
                'right_column_order' => $newRightOrder,
                'left_column_order' => $newLeftOrder,
                'creator_display_limit' => 40,
                'contributor_display_limit' => 60,
                'citation_author_display_limit' => 70,
            ]);

        $response->assertOk();

        $template->refresh();

        expect($template->name)->toBe('Updated Name')
            ->and($template->right_column_order)->toBe($newRightOrder)
            ->and($template->left_column_order)->toBe($newLeftOrder)
            ->and($template->creator_display_limit)->toBe(40)
            ->and($template->contributor_display_limit)->toBe(60)
            ->and($template->citation_author_display_limit)->toBe(70);
    });

    it('normalizes location to the end when it is submitted in the middle of the right column order', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $submittedRightOrder = [
            'abstract',
            'location',
            'methods',
            'technical_info',
            'series_information',
            'table_of_contents',
            'other',
            'creators',
            'contributors',
            'funders',
            'keywords',
            'metadata_download',
        ];

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'right_column_order' => $submittedRightOrder,
            ])
            ->assertOk();

        expect($template->fresh()->right_column_order)->toBe(LandingPageTemplate::RIGHT_COLUMN_SECTIONS);
    });

    it('prevents updating the default template', function (): void {
        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$this->defaultTemplate->id}", ['name' => 'Hacked'])
            ->assertForbidden()
            ->assertJson([
                'message' => 'Only creator, contributor, and citation author display limits can be updated on default templates.',
                'error' => 'default_template_immutable',
            ]);
    });

    it('allows updating display limits on default templates', function (): void {
        $this->actingAs($this->groupLeader)
            ->putJson("/landing-pages/{$this->defaultTemplate->id}", [
                'creator_display_limit' => 35,
                'contributor_display_limit' => 45,
                'citation_author_display_limit' => 55,
            ])
            ->assertOk()
            ->assertJsonPath('template.creator_display_limit', 35)
            ->assertJsonPath('template.contributor_display_limit', 45)
            ->assertJsonPath('template.citation_author_display_limit', 55);

        $fresh = $this->defaultTemplate->fresh();

        expect($fresh?->creator_display_limit)->toBe(35)
            ->and($fresh?->contributor_display_limit)->toBe(45)
            ->and($fresh?->citation_author_display_limit)->toBe(55);
    });

    it('rejects display limits outside the supported range', function (mixed $value): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'creator_display_limit' => $value,
                'contributor_display_limit' => $value,
                'citation_author_display_limit' => $value,
            ])
            ->assertJsonValidationErrors(['creator_display_limit', 'contributor_display_limit', 'citation_author_display_limit']);
    })->with([0, -1, 501, 'abc', 10.5]);

    it('forgets affected cached public landing page render data after template updates', function (): void {
        Cache::flush();

        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);
        $resource = Resource::factory()->create();
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'landing_page_template_id' => $template->id,
        ]);

        $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
        $schemaOrgCacheKey = CacheKey::SCHEMA_ORG_JSONLD->key($resource->id);
        Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put($cacheKey, ['template' => 'default_gfz', 'props' => []], 600);
        Cache::tags(CacheKey::SCHEMA_ORG_JSONLD->tags())->put($schemaOrgCacheKey, ['@context' => 'https://schema.org'], 600);

        expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($cacheKey))->toBeTrue()
            ->and(Cache::tags(CacheKey::SCHEMA_ORG_JSONLD->tags())->has($schemaOrgCacheKey))->toBeTrue();

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'creator_display_limit' => 45,
                'contributor_display_limit' => 55,
                'citation_author_display_limit' => 65,
            ])
            ->assertOk();

        expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($cacheKey))->toBeFalse()
            ->and(Cache::tags(CacheKey::SCHEMA_ORG_JSONLD->tags())->has($schemaOrgCacheKey))->toBeTrue();
    });

    it('does not forget cached public landing page render data for empty template updates', function (): void {
        Cache::flush();

        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);
        $resource = Resource::factory()->create();
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'landing_page_template_id' => $template->id,
        ]);

        $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
        Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put($cacheKey, ['template' => 'default_gfz', 'props' => []], 600);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [])
            ->assertOk();

        expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($cacheKey))->toBeTrue();
    });

    it('does not forget cached public landing page render data when submitted template values are unchanged', function (): void {
        Cache::flush();

        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->admin->id,
            'creator_display_limit' => 45,
            'contributor_display_limit' => 55,
            'citation_author_display_limit' => 65,
        ]);
        $resource = Resource::factory()->create();
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'landing_page_template_id' => $template->id,
        ]);

        $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
        Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put($cacheKey, ['template' => 'default_gfz', 'props' => []], 600);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'creator_display_limit' => 45,
                'contributor_display_limit' => 55,
                'citation_author_display_limit' => 65,
            ])
            ->assertOk();

        expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($cacheKey))->toBeTrue();
    });

    it('rejects invalid section keys in right column', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'name' => 'Valid Name',
                'right_column_order' => ['abstract', 'invalid_section'],
            ])
            ->assertJsonValidationErrors(['right_column_order']);
    });

    it('rejects right column with missing sections', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        // Missing some sections
        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'name' => 'Valid Name',
                'right_column_order' => ['abstract', 'creators'],
            ])
            ->assertJsonValidationErrors(['right_column_order']);
    });

    it('rejects left column with invalid sections', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'name' => 'Valid Name',
                'left_column_order' => ['files', 'nonexistent'],
            ])
            ->assertJsonValidationErrors(['left_column_order']);
    });

    it('rejects files in the left column for igsn templates', function (): void {
        $template = LandingPageTemplate::factory()->igsn()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'name' => 'Valid Name',
                'left_column_order' => ['files', 'contact', 'model_description', 'related_work', 'general'],
            ])
            ->assertJsonValidationErrors(['left_column_order']);
    });

    it('requires citation exactly once when a left-column order is submitted', function (array $leftOrder): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'left_column_order' => $leftOrder,
            ])
            ->assertJsonValidationErrors(['left_column_order']);
    })->with([
        'missing citation' => [['files', 'dates', 'contact', 'model_description', 'related_work']],
        'duplicate citation' => [
            ['files', 'citation', 'citation', 'dates', 'contact', 'model_description', 'related_work'],
        ],
    ]);
});

// ─── Delete ──────────────────────────────────────────────────────────────────

describe('Delete', function (): void {
    it('deletes a custom template', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/landing-pages/{$template->id}");

        $response->assertOk();

        expect(LandingPageTemplate::find($template->id))->toBeNull();
    });

    it('prevents deleting the default template', function (): void {
        $this->actingAs($this->admin)
            ->deleteJson("/landing-pages/{$this->defaultTemplate->id}")
            ->assertForbidden();
    });

    it('rejects deletion when template is in use', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        // Create a landing page using this template
        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'landing_page_template_id' => $template->id,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/landing-pages/{$template->id}")
            ->assertStatus(422);
    });
});

// ─── Logo Upload ─────────────────────────────────────────────────────────────

describe('Logo Upload', function (): void {
    it('uploads a logo to a custom template', function (): void {
        Storage::fake('public');

        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $file = UploadedFile::fake()->image('logo.png', 200, 100);

        $response = $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$template->id}/logo", [
                'logo' => $file,
            ]);

        $response->assertOk();

        $template->refresh();

        expect($template->logo_path)->not->toBeNull()
            ->and($template->logo_filename)->toBe('logo.png');

        Storage::disk('public')->assertExists($template->logo_path);
    });

    it('rejects logo upload for default template', function (): void {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('logo.png');

        $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$this->defaultTemplate->id}/logo", [
                'logo' => $file,
            ])
            ->assertForbidden();
    });

    it('rejects non-image files', function (): void {
        Storage::fake('public');

        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$template->id}/logo", [
                'logo' => $file,
            ])
            ->assertJsonValidationErrors(['logo']);
    });

    it('rejects oversized logo', function (): void {
        Storage::fake('public');

        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $file = UploadedFile::fake()->image('huge-logo.png')->size(3000); // 3MB > 2MB limit

        $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$template->id}/logo", [
                'logo' => $file,
            ])
            ->assertJsonValidationErrors(['logo']);
    });

    it('deletes a logo from a custom template', function (): void {
        Storage::fake('public');

        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->admin->id,
        ]);

        // Upload a logo first
        $file = UploadedFile::fake()->image('logo.png', 200, 100);
        $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$template->id}/logo", ['logo' => $file]);

        $template->refresh();

        expect($template->logo_path)->not->toBeNull();

        // Delete the logo
        $response = $this->actingAs($this->admin)
            ->deleteJson("/landing-pages/{$template->id}/logo");

        $response->assertOk();

        $template->refresh();

        expect($template->logo_path)->toBeNull()
            ->and($template->logo_filename)->toBeNull();
    });
});

// ─── API List ────────────────────────────────────────────────────────────────

describe('API List', function (): void {
    it('returns all templates for authenticated users', function (): void {
        LandingPageTemplate::factory()->count(2)->create(['created_by' => $this->admin->id]);

        $response = $this->actingAs($this->curator) // Even curators can list templates
            ->getJson('/api/landing-page-templates');

        $response->assertOk()
            ->assertJsonCount(4, 'templates') // resource default + IGSN default + 2 custom
            ->assertJsonStructure([
                'templates' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'is_default',
                        'template_type',
                        'logo_path',
                        'right_column_order',
                        'left_column_order',
                    ],
                ],
            ]);
    });

    it('returns defaults first and sorts resource templates ahead of igsn templates', function (): void {
        LandingPageTemplate::factory()->create([
            'name' => 'Zulu Resource Clone',
            'template_type' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
            'created_by' => $this->admin->id,
        ]);

        LandingPageTemplate::factory()->create([
            'name' => 'Alpha Resource Clone',
            'template_type' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
            'created_by' => $this->admin->id,
        ]);

        LandingPageTemplate::factory()->igsn()->create([
            'name' => 'Zulu IGSN Clone',
            'created_by' => $this->admin->id,
        ]);

        LandingPageTemplate::factory()->igsn()->create([
            'name' => 'Alpha IGSN Clone',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->curator)
            ->getJson('/api/landing-page-templates');

        $response->assertOk();

        expect(array_column($response->json('templates'), 'name'))
            ->toBe([
                LandingPageTemplate::DEFAULT_TEMPLATE_NAME,
                LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_NAME,
                'Alpha Resource Clone',
                'Zulu Resource Clone',
                'Alpha IGSN Clone',
                'Zulu IGSN Clone',
            ]);
    });

    it('normalizes legacy igsn left-column orders in the API list response', function (): void {
        $storedOrder = ['contact', 'files', 'model_description', 'related_work'];

        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => $this->admin->id,
            'left_column_order' => $storedOrder,
        ]);

        $response = $this->actingAs($this->curator)
            ->getJson('/api/landing-page-templates');

        $serializedTemplate = collect($response->json('templates'))
            ->firstWhere('id', $template->id);

        expect($serializedTemplate)
            ->not->toBeNull()
            ->and($serializedTemplate['left_column_order'])->toBe([
                'contact',
                'model_description',
                'related_work',
                'general',
                'acquisition',
                'dates',
                'citation',
            ])
            ->and($template->fresh()?->left_column_order)->toBe($storedOrder);
    });

    it('ensures both system default templates exist when listing', function (): void {
        // Remove all templates to simulate a fresh environment.
        LandingPageTemplate::query()->delete();

        $response = $this->actingAs($this->curator)
            ->getJson('/api/landing-page-templates');

        $response->assertOk();

        $templateTypes = collect($response->json('templates'))
            ->pluck('template_type')
            ->all();

        expect($templateTypes)
            ->toContain(LandingPageTemplate::TEMPLATE_TYPE_RESOURCE)
            ->toContain(LandingPageTemplate::TEMPLATE_TYPE_IGSN);
    });

    it('rejects unauthenticated access to API list', function (): void {
        $this->getJson('/api/landing-page-templates')
            ->assertUnauthorized();
    });
});

// ─── Model ───────────────────────────────────────────────────────────────────

describe('Model', function (): void {
    it('appends citation after all other missing sections in sparse legacy orders', function (): void {
        expect(LandingPageTemplate::normalizeLeftColumnOrder(
            ['contact', 'files', 'unknown'],
            LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
        ))->toBe([
            'contact',
            'files',
            'dates',
            'model_description',
            'related_work',
            'citation',
        ])->and(LandingPageTemplate::normalizeLeftColumnOrder(
            ['contact', 'general', 'files'],
            LandingPageTemplate::TEMPLATE_TYPE_IGSN,
        ))->toBe([
            'contact',
            'general',
            'acquisition',
            'dates',
            'model_description',
            'related_work',
            'citation',
        ]);
    });

    it('preserves a stored citation position while filling sparse orders', function (): void {
        expect(LandingPageTemplate::normalizeLeftColumnOrder(
            ['contact', 'citation', 'files'],
            LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
        ))->toBe([
            'contact',
            'citation',
            'files',
            'dates',
            'model_description',
            'related_work',
        ])->and(LandingPageTemplate::normalizeLeftColumnOrder(
            ['citation', 'contact', 'general'],
            LandingPageTemplate::TEMPLATE_TYPE_IGSN,
        ))->toBe([
            'citation',
            'contact',
            'general',
            'acquisition',
            'dates',
            'model_description',
            'related_work',
        ]);
    });

    it('restores citation at the canonical position in legacy system defaults', function (): void {
        $this->defaultTemplate->update([
            'left_column_order' => ['files', 'dates', 'contact', 'model_description', 'related_work'],
        ]);
        $this->igsnDefaultTemplate->update([
            'left_column_order' => ['general', 'acquisition', 'dates', 'contact', 'model_description', 'related_work'],
        ]);

        LandingPageTemplate::ensureSystemTemplatesExist();

        expect($this->defaultTemplate->fresh()?->left_column_order)
            ->toBe(LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS)
            ->and($this->igsnDefaultTemplate->fresh()?->left_column_order)
            ->toBe(LandingPageTemplate::IGSN_LEFT_COLUMN_SECTIONS);
    });

    it('does not update updated_at when default template is already normalized', function (): void {
        $originalTimestamp = now()->subHour()->startOfSecond();

        LandingPageTemplate::query()
            ->whereKey($this->defaultTemplate->id)
            ->update(['updated_at' => $originalTimestamp]);

        $template = LandingPageTemplate::ensureDefaultTemplateExists();

        expect(Carbon::parse((string) $template->fresh()?->updated_at)->equalTo($originalTimestamp))->toBeTrue();
    });

    it('restores canonical fields when default template is corrupted', function (): void {
        // Corrupt the default template with creator and logo.
        LandingPageTemplate::query()
            ->whereKey($this->defaultTemplate->id)
            ->update([
                'created_by' => $this->admin->id,
                'logo_path' => 'landing-page-logos/test/logo.png',
                'logo_filename' => 'logo.png',
            ]);

        // Ensure the default template, which should restore canonical fields.
        $template = LandingPageTemplate::ensureDefaultTemplateExists();
        $fresh = $template->fresh();

        expect($fresh->created_by)->toBeNull()
            ->and($fresh->logo_path)->toBeNull()
            ->and($fresh->logo_filename)->toBeNull()
            ->and($fresh->is_default)->toBeTrue();
    });

    it('does not overwrite customized display limits when normalizing default templates', function (): void {
        $this->defaultTemplate->update([
            'creator_display_limit' => 33,
            'contributor_display_limit' => 44,
            'citation_author_display_limit' => 55,
        ]);

        $template = LandingPageTemplate::ensureDefaultTemplateExists();

        expect($template->creator_display_limit)->toBe(33)
            ->and($template->contributor_display_limit)->toBe(44)
            ->and($template->citation_author_display_limit)->toBe(55);
    });

    it('returns null logo_url when no logo is set', function (): void {
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->admin->id,
            'logo_path' => null,
        ]);

        expect($template->logo_url)->toBeNull();
    });

    it('returns full logo_url when logo_path is set', function (): void {
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->admin->id,
            'logo_path' => 'landing-page-logos/test/logo.png',
        ]);

        expect($template->logo_url)->toContain('storage/landing-page-logos/test/logo.png');
    });

    it('identifies default template correctly', function (): void {
        expect($this->defaultTemplate->isDefault())->toBeTrue();

        $custom = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);
        expect($custom->isDefault())->toBeFalse();
    });

    it('detects when template is in use', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        expect($template->isInUse())->toBeFalse();

        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'landing_page_template_id' => $template->id,
        ]);

        expect($template->isInUse())->toBeTrue();
    });

    it('returns correct usage count', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        expect($template->getUsageCount())->toBe(0);

        $resources = Resource::factory()->count(3)->create();
        foreach ($resources as $resource) {
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'landing_page_template_id' => $template->id,
            ]);
        }

        expect($template->getUsageCount())->toBe(3);
    });

    it('scopes custom templates excluding default', function (): void {
        LandingPageTemplate::factory()->count(2)->create(['created_by' => $this->admin->id]);

        $custom = LandingPageTemplate::custom()->get();

        expect($custom)->toHaveCount(2)
            ->and($custom->pluck('is_default')->unique()->toArray())->toBe([false]);
    });

    it('validates section order with valid input', function (): void {
        $valid = LandingPageTemplate::isValidSectionOrder(
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        );

        expect($valid)->toBeTrue();
    });

    it('validates section order with reordered input', function (): void {
        $valid = LandingPageTemplate::isValidSectionOrder(
            locationFirstRightColumnOrder(),
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        );

        expect($valid)->toBeTrue();
    });

    it('rejects section order with wrong count', function (): void {
        $valid = LandingPageTemplate::isValidSectionOrder(
            ['abstract', 'creators'],
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        );

        expect($valid)->toBeFalse();
    });

    it('rejects section order with invalid keys', function (): void {
        $valid = LandingPageTemplate::isValidSectionOrder(
            ['abstract', 'methods', 'technical_info', 'series_information', 'table_of_contents', 'other', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download', 'invalid_key'],
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        );

        expect($valid)->toBeFalse();
    });

    it('rejects section order with duplicate keys', function (): void {
        $valid = LandingPageTemplate::isValidSectionOrder(
            ['abstract', 'methods', 'technical_info', 'series_information', 'table_of_contents', 'other', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download', 'abstract'],
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        );

        expect($valid)->toBeFalse();
    });

    it('has creator relationship', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        expect($template->creator)->toBeInstanceOf(User::class)
            ->and($template->creator->id)->toBe($this->admin->id);
    });

    it('has landingPages relationship', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'landing_page_template_id' => $template->id,
        ]);

        expect($template->landingPages)->toHaveCount(1);
    });

    it('casts right_column_order as array', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        expect($template->right_column_order)->toBeArray()
            ->and($template->left_column_order)->toBeArray();
    });

    it('casts is_default as boolean', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        expect($template->is_default)->toBeBool();
    });

    it('appends logo_url attribute', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);
        $json = $template->toArray();

        expect($json)->toHaveKey('logo_url');
    });
});

// ─── Factory ─────────────────────────────────────────────────────────────────

describe('Factory', function (): void {
    it('creates a valid template with defaults', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        expect($template->name)->toBeString()
            ->and($template->slug)->toBeString()
            ->and($template->is_default)->toBeFalse()
            ->and($template->logo_path)->toBeNull()
            ->and($template->logo_filename)->toBeNull()
            ->and($template->right_column_order)->toBe(LandingPageTemplate::RIGHT_COLUMN_SECTIONS)
            ->and($template->left_column_order)->toBe(LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS)
            ->and($template->creator_display_limit)->toBe(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT)
            ->and($template->contributor_display_limit)->toBe(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT)
            ->and($template->citation_author_display_limit)->toBe(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT);
    });

    it('creates an igsn template with igsn left-column defaults', function (): void {
        $template = LandingPageTemplate::factory()->igsn()->create(['created_by' => $this->admin->id]);

        expect($template->template_type)->toBe(LandingPageTemplate::TEMPLATE_TYPE_IGSN)
            ->and($template->left_column_order)->toBe(LandingPageTemplate::IGSN_LEFT_COLUMN_SECTIONS);
    });

    it('creates a default template via state', function (): void {
        $template = LandingPageTemplate::factory()->default()->make();

        expect($template->name)->toBe('Default GFZ Data Services')
            ->and($template->slug)->toBe(LandingPageTemplate::DEFAULT_TEMPLATE_SLUG)
            ->and($template->is_default)->toBeTrue()
            ->and($template->created_by)->toBeNull();
    });

    it('creates a template with custom section order', function (): void {
        $rightOrder = locationFirstRightColumnOrder();
        $leftOrder = ['contact', 'files', 'citation', 'dates', 'model_description', 'related_work'];

        $template = LandingPageTemplate::factory()
            ->withSectionOrder($rightOrder, $leftOrder)
            ->create(['created_by' => $this->admin->id]);

        expect($template->right_column_order)->toBe($rightOrder)
            ->and($template->left_column_order)->toBe($leftOrder);
    });

    it('creates a template with logo via state', function (): void {
        $template = LandingPageTemplate::factory()
            ->withLogo('custom/path/logo.svg', 'my-logo.svg')
            ->create(['created_by' => $this->admin->id]);

        expect($template->logo_path)->toBe('custom/path/logo.svg')
            ->and($template->logo_filename)->toBe('my-logo.svg')
            ->and($template->logo_url)->toContain('storage/custom/path/logo.svg');
    });
});

// ─── Seeder ──────────────────────────────────────────────────────────────────

describe('Seeder', function (): void {
    it('seeds the default template', function (): void {
        // Default template already seeded in beforeEach, verify it exists
        $default = LandingPageTemplate::where('slug', LandingPageTemplate::DEFAULT_TEMPLATE_SLUG)->first();

        expect($default)->not->toBeNull()
            ->and($default->name)->toBe('Default GFZ Data Services')
            ->and($default->is_default)->toBeTrue()
            ->and($default->right_column_order)->toBe(LandingPageTemplate::RIGHT_COLUMN_SECTIONS)
            ->and($default->left_column_order)->toBe(LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS);
    });

    it('does not duplicate default template when seeder runs again', function (): void {
        // Run the seeder a second time
        $this->seed(LandingPageTemplateSeeder::class);

        $count = LandingPageTemplate::where('slug', LandingPageTemplate::DEFAULT_TEMPLATE_SLUG)->count();

        expect($count)->toBe(1);
    });

    it('seeds the IGSN default template alongside the resource default', function (): void {
        $igsn = LandingPageTemplate::where('slug', LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG)->first();

        expect($igsn)->not->toBeNull()
            ->and($igsn?->name)->toBe(LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_NAME)
            ->and($igsn?->is_default)->toBeTrue()
            ->and($igsn?->template_type)->toBe(LandingPageTemplate::TEMPLATE_TYPE_IGSN)
            ->and($igsn?->left_column_order)->toBe(LandingPageTemplate::IGSN_LEFT_COLUMN_SECTIONS);
    });

    it('does not duplicate the IGSN default when seeder runs again', function (): void {
        $this->seed(LandingPageTemplateSeeder::class);

        $count = LandingPageTemplate::where('slug', LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG)->count();

        expect($count)->toBe(1);
    });
});

// ─── Update Edge Cases ───────────────────────────────────────────────────────

describe('Update Edge Cases', function (): void {
    it('updates only a display limit without persisting a normalized sparse legacy order', function (): void {
        $storedLeftOrder = ['contact', 'files'];
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->admin->id,
            'left_column_order' => $storedLeftOrder,
            'creator_display_limit' => 12,
            'contributor_display_limit' => 34,
            'citation_author_display_limit' => 8,
        ]);
        $originalName = $template->name;
        $originalRightOrder = $template->right_column_order;

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'citation_author_display_limit' => 21,
            ])
            ->assertOk()
            ->assertJsonPath('template.citation_author_display_limit', 21)
            ->assertJsonPath('template.left_column_order', [
                'contact',
                'files',
                'dates',
                'model_description',
                'related_work',
                'citation',
            ]);

        $template->refresh();

        expect($template->name)->toBe($originalName)
            ->and($template->right_column_order)->toBe($originalRightOrder)
            ->and($template->left_column_order)->toBe($storedLeftOrder)
            ->and($template->creator_display_limit)->toBe(12)
            ->and($template->contributor_display_limit)->toBe(34)
            ->and($template->citation_author_display_limit)->toBe(21);
    });
    it('updates only the name without section order', function (): void {
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->admin->id,
            'left_column_order' => ['contact', 'files'],
        ]);
        $originalRightOrder = $template->right_column_order;
        $originalLeftOrder = $template->left_column_order;

        $response = $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", ['name' => 'Only Name Changed'])
            ->assertOk();

        $response->assertJsonPath('template.left_column_order', [
            'contact', 'files', 'dates', 'model_description', 'related_work', 'citation',
        ]);

        $template->refresh();

        expect($template->name)->toBe('Only Name Changed')
            ->and($template->right_column_order)->toBe($originalRightOrder)
            // A partial update must not persist the lazy runtime backfill.
            ->and($template->left_column_order)->toBe($originalLeftOrder);
    });

    it('updates only right column order', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);
        $originalName = $template->name;
        $originalLeftOrder = $template->left_column_order;

        $newRightOrder = locationFirstRightColumnOrder();

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", ['right_column_order' => $newRightOrder])
            ->assertOk();

        $template->refresh();

        expect($template->name)->toBe($originalName)
            ->and($template->right_column_order)->toBe($newRightOrder)
            ->and($template->left_column_order)->toBe($originalLeftOrder);
    });

    it('allows group leaders to update custom templates', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->groupLeader)
            ->putJson("/landing-pages/{$template->id}", ['name' => 'GL Update'])
            ->assertOk();

        expect($template->fresh()->name)->toBe('GL Update');
    });

    it('rejects duplicate name when updating', function (): void {
        $template1 = LandingPageTemplate::factory()->create([
            'name' => 'First Template',
            'created_by' => $this->admin->id,
        ]);
        $template2 = LandingPageTemplate::factory()->create([
            'name' => 'Second Template',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template2->id}", ['name' => 'First Template'])
            ->assertJsonValidationErrors(['name']);
    });

    it('allows updating template with its own name', function (): void {
        $template = LandingPageTemplate::factory()->create([
            'name' => 'My Template',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", ['name' => 'My Template'])
            ->assertOk();
    });

    it('validates left column section order completeness', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        // Only 2 of 6 required left column sections
        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'left_column_order' => ['files', 'contact'],
            ])
            ->assertJsonValidationErrors(['left_column_order']);
    });
});

// ─── Delete with Logo Cleanup ────────────────────────────────────────────────

describe('Delete with Logo Cleanup', function (): void {
    it('deletes logo file when deleting a template with logo', function (): void {
        Storage::fake('public');

        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        // Upload a logo
        $file = UploadedFile::fake()->image('logo.png', 200, 100);
        $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$template->id}/logo", ['logo' => $file])
            ->assertOk();

        $template->refresh();
        $logoPath = $template->logo_path;

        Storage::disk('public')->assertExists($logoPath);

        // Delete the template
        $this->actingAs($this->admin)
            ->deleteJson("/landing-pages/{$template->id}")
            ->assertOk();

        Storage::disk('public')->assertMissing($logoPath);
    });

    it('replaces old logo when uploading new one', function (): void {
        Storage::fake('public');

        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        // Upload first logo
        $file1 = UploadedFile::fake()->image('old-logo.png', 200, 100);
        $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$template->id}/logo", ['logo' => $file1])
            ->assertOk();

        $template->refresh();
        $oldLogoPath = $template->logo_path;

        // Upload replacement logo
        $file2 = UploadedFile::fake()->image('new-logo.png', 300, 150);
        $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$template->id}/logo", ['logo' => $file2])
            ->assertOk();

        $template->refresh();

        Storage::disk('public')->assertMissing($oldLogoPath);
        Storage::disk('public')->assertExists($template->logo_path);
        expect($template->logo_filename)->toBe('new-logo.png');
    });

    it('handles deleting logo when no logo exists', function (): void {
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->admin->id,
            'logo_path' => null,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/landing-pages/{$template->id}/logo")
            ->assertOk();

        $template->refresh();
        expect($template->logo_path)->toBeNull();
    });

    it('denies logo deletion for default template', function (): void {
        $this->actingAs($this->admin)
            ->deleteJson("/landing-pages/{$this->defaultTemplate->id}/logo")
            ->assertForbidden();
    });

    it('returns 500 when logo storage fails', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $file = UploadedFile::fake()->image('logo.png', 200, 100);

        // Mock Storage facade to throw exception simulating disk failure
        Storage::shouldReceive('disk')
            ->with('public')
            ->andReturnUsing(function () {
                $mock = Mockery::mock(Filesystem::class);
                $mock->shouldReceive('putFileAs')->andThrow(new RuntimeException('Disk full'));

                return $mock;
            });

        $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$template->id}/logo", [
                'logo' => $file,
            ])
            ->assertStatus(500)
            ->assertJson(['message' => 'Failed to store logo file']);
    });

    it('handles logo upload without file gracefully', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->postJson("/landing-pages/{$template->id}/logo", [])
            ->assertJsonValidationErrors(['logo']);
    });
});

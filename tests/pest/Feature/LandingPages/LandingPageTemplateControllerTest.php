<?php

declare(strict_types=1);

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
use Illuminate\Http\UploadedFile;
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

    // Seed default template
    $this->defaultTemplate = LandingPageTemplate::factory()->default()->create();
});

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
                ->has('templates', 3) // default + 2 custom
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
            ->and($template->left_column_order)->toBe(LandingPageTemplate::LEFT_COLUMN_SECTIONS);
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
});

// ─── Update ──────────────────────────────────────────────────────────────────

describe('Update', function (): void {
    it('updates template name and section order', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $newRightOrder = ['location', 'descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'];
        $newLeftOrder = ['contact', 'files', 'model_description', 'related_work'];

        $response = $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'name' => 'Updated Name',
                'right_column_order' => $newRightOrder,
                'left_column_order' => $newLeftOrder,
            ]);

        $response->assertOk();

        $template->refresh();

        expect($template->name)->toBe('Updated Name')
            ->and($template->right_column_order)->toBe($newRightOrder)
            ->and($template->left_column_order)->toBe($newLeftOrder);
    });

    it('prevents updating the default template', function (): void {
        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$this->defaultTemplate->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    });

    it('rejects invalid section keys in right column', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'name' => 'Valid Name',
                'right_column_order' => ['descriptions', 'invalid_section'],
            ])
            ->assertJsonValidationErrors(['right_column_order']);
    });

    it('rejects right column with missing sections', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);

        // Missing some sections
        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", [
                'name' => 'Valid Name',
                'right_column_order' => ['descriptions', 'creators'],
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
            ->assertJsonCount(3, 'templates'); // default + 2 custom
    });

    it('rejects unauthenticated access to API list', function (): void {
        $this->getJson('/api/landing-page-templates')
            ->assertUnauthorized();
    });
});

// ─── Model ───────────────────────────────────────────────────────────────────

describe('Model', function (): void {
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
            ['descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download', 'location'],
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        );

        expect($valid)->toBeTrue();
    });

    it('validates section order with reordered input', function (): void {
        $valid = LandingPageTemplate::isValidSectionOrder(
            ['location', 'descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        );

        expect($valid)->toBeTrue();
    });

    it('rejects section order with wrong count', function (): void {
        $valid = LandingPageTemplate::isValidSectionOrder(
            ['descriptions', 'creators'],
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        );

        expect($valid)->toBeFalse();
    });

    it('rejects section order with invalid keys', function (): void {
        $valid = LandingPageTemplate::isValidSectionOrder(
            ['descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download', 'invalid_key'],
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        );

        expect($valid)->toBeFalse();
    });

    it('rejects section order with duplicate keys', function (): void {
        $valid = LandingPageTemplate::isValidSectionOrder(
            ['descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download', 'descriptions'],
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
            ->and($template->left_column_order)->toBe(LandingPageTemplate::LEFT_COLUMN_SECTIONS);
    });

    it('creates a default template via state', function (): void {
        $template = LandingPageTemplate::factory()->default()->make();

        expect($template->name)->toBe('Default GFZ Data Services')
            ->and($template->slug)->toBe('default_gfz')
            ->and($template->is_default)->toBeTrue()
            ->and($template->created_by)->toBeNull();
    });

    it('creates a template with custom section order', function (): void {
        $rightOrder = ['location', 'descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'];
        $leftOrder = ['contact', 'files', 'model_description', 'related_work'];

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
        $default = LandingPageTemplate::where('slug', 'default_gfz')->first();

        expect($default)->not->toBeNull()
            ->and($default->name)->toBe('Default GFZ Data Services')
            ->and($default->is_default)->toBeTrue()
            ->and($default->right_column_order)->toBe(LandingPageTemplate::RIGHT_COLUMN_SECTIONS)
            ->and($default->left_column_order)->toBe(LandingPageTemplate::LEFT_COLUMN_SECTIONS);
    });

    it('does not duplicate default template when seeder runs again', function (): void {
        // Run the seeder a second time
        $this->seed(\Database\Seeders\LandingPageTemplateSeeder::class);

        $count = LandingPageTemplate::where('slug', 'default_gfz')->count();

        expect($count)->toBe(1);
    });
});

// ─── Update Edge Cases ───────────────────────────────────────────────────────

describe('Update Edge Cases', function (): void {
    it('updates only the name without section order', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);
        $originalRightOrder = $template->right_column_order;
        $originalLeftOrder = $template->left_column_order;

        $this->actingAs($this->admin)
            ->putJson("/landing-pages/{$template->id}", ['name' => 'Only Name Changed'])
            ->assertOk();

        $template->refresh();

        expect($template->name)->toBe('Only Name Changed')
            ->and($template->right_column_order)->toBe($originalRightOrder)
            ->and($template->left_column_order)->toBe($originalLeftOrder);
    });

    it('updates only right column order', function (): void {
        $template = LandingPageTemplate::factory()->create(['created_by' => $this->admin->id]);
        $originalName = $template->name;
        $originalLeftOrder = $template->left_column_order;

        $newRightOrder = ['location', 'descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'];

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

        // Only 2 of 4 required left column sections
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

        // Mock Storage facade - putFileAs returns false to simulate storage failure
        $fakeDisk = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $fakeDisk->shouldReceive('delete')->andReturn(true);
        $fakeDisk->shouldReceive('putFileAs')->once()->andReturn(false);

        Storage::shouldReceive('disk')->with('public')->andReturn($fakeDisk);

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

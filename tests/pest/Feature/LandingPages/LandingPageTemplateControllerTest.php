<?php

declare(strict_types=1);

use App\Http\Controllers\LandingPageTemplateController;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

covers(LandingPageTemplateController::class);

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

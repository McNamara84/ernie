<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('slug immutability', function () {
    test('throws RuntimeException when slug is modified', function () {
        $landingPage = LandingPage::factory()->create();
        $landingPage->slug = 'new-slug-value';
        $landingPage->save();
    })->throws(\RuntimeException::class, 'Cannot modify landing page slug');

    test('allows updating other fields without slug change', function () {
        $landingPage = LandingPage::factory()->create(['view_count' => 0]);
        $landingPage->update(['view_count' => 42]);

        expect($landingPage->fresh()->view_count)->toBe(42);
    });
});

describe('publish / unpublish', function () {
    test('publish sets is_published and published_at', function () {
        $landingPage = LandingPage::factory()->draft()->create();
        $landingPage->publish();

        $fresh = $landingPage->fresh();
        expect($fresh->is_published)->toBeTrue()
            ->and($fresh->published_at)->not->toBeNull();
    });

    test('unpublish clears is_published and published_at', function () {
        $landingPage = LandingPage::factory()->create(['is_published' => true, 'published_at' => now()]);
        $landingPage->unpublish();

        $fresh = $landingPage->fresh();
        expect($fresh->is_published)->toBeFalse()
            ->and($fresh->published_at)->toBeNull();
    });
});

describe('status checks', function () {
    test('isPublished returns true for published page', function () {
        $landingPage = LandingPage::factory()->create(['is_published' => true]);

        expect($landingPage->isPublished())->toBeTrue()
            ->and($landingPage->isDraft())->toBeFalse();
    });

    test('isDraft returns true for unpublished page', function () {
        $landingPage = LandingPage::factory()->draft()->create();

        expect($landingPage->isDraft())->toBeTrue()
            ->and($landingPage->isPublished())->toBeFalse();
    });

    test('status accessor returns correct string', function () {
        $published = LandingPage::factory()->create(['is_published' => true]);
        $draft = LandingPage::factory()->draft()->create();

        expect($published->status)->toBe('published')
            ->and($draft->status)->toBe('draft');
    });
});

describe('incrementViewCount', function () {
    test('increments view count and updates last viewed', function () {
        $landingPage = LandingPage::factory()->create(['view_count' => 5, 'last_viewed_at' => null]);
        $landingPage->incrementViewCount();

        $fresh = $landingPage->fresh();
        expect($fresh->view_count)->toBe(6)
            ->and($fresh->last_viewed_at)->not->toBeNull();
    });
});

describe('URL accessors', function () {
    test('public_url uses DOI prefix when available', function () {
        $landingPage = LandingPage::factory()->create([
            'doi_prefix' => '10.5880/test.2024.001',
            'slug' => 'my-dataset',
        ]);

        expect($landingPage->public_url)->toContain('/10.5880/test.2024.001/my-dataset');
    });

    test('public_url uses draft prefix when no DOI', function () {
        $resource = Resource::factory()->create();
        $landingPage = LandingPage::factory()->withoutDoi()->create([
            'resource_id' => $resource->id,
            'slug' => 'my-dataset',
        ]);

        expect($landingPage->public_url)->toContain("/draft-{$resource->id}/my-dataset");
    });

    test('preview_url appends preview token', function () {
        $landingPage = LandingPage::factory()->create([
            'doi_prefix' => '10.5880/test',
            'slug' => 'test',
            'preview_token' => 'abc123',
        ]);

        expect($landingPage->preview_url)->toContain('?preview=abc123');
    });

    test('preview_url returns null without token', function () {
        $landingPage = LandingPage::factory()->create();
        // Bypass boot event that auto-generates token by updating directly
        $landingPage->forceFill(['preview_token' => null])->saveQuietly();

        expect($landingPage->fresh()->preview_url)->toBeNull();
    });

    test('contact_url appends /contact to public path', function () {
        $landingPage = LandingPage::factory()->create([
            'doi_prefix' => '10.5880/test',
            'slug' => 'test',
        ]);

        expect($landingPage->contact_url)->toContain('/10.5880/test/test/contact');
    });
});

describe('generateSlugFromResource', function () {
    test('generates slug from main title', function () {
        $resource = Resource::factory()->create();
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle']
        );
        Title::create(['resource_id' => $resource->id, 'value' => 'My Important Dataset', 'title_type_id' => $titleType->id]);

        $landingPage = new LandingPage(['resource_id' => $resource->id]);
        $slug = $landingPage->generateSlugFromResource();

        expect($slug)->toContain('my-important-dataset');
    });

    test('returns fallback slug when no main title', function () {
        $resource = Resource::factory()->create();

        $landingPage = new LandingPage(['resource_id' => $resource->id]);
        $slug = $landingPage->generateSlugFromResource();

        expect($slug)->toBe("dataset-{$resource->id}");
    });

    test('throws when resource not found', function () {
        $landingPage = new LandingPage(['resource_id' => 99999]);
        $landingPage->generateSlugFromResource();
    })->throws(\InvalidArgumentException::class, 'Cannot generate slug');
});

describe('auto-generated fields on creation', function () {
    test('generates preview token if not set', function () {
        $landingPage = LandingPage::factory()->create(['preview_token' => null]);

        // Model boot creates a preview_token
        expect($landingPage->preview_token)->not->toBeNull()
            ->and(strlen($landingPage->preview_token))->toBe(64);
    });

    test('generates slug from resource title if not set', function () {
        $resource = Resource::factory()->create();
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle']
        );
        Title::create(['resource_id' => $resource->id, 'value' => 'Auto Slug Test', 'title_type_id' => $titleType->id]);

        $landingPage = LandingPage::create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
        ]);

        expect($landingPage->slug)->not->toBeEmpty()
            ->and($landingPage->slug)->toContain('auto-slug-test');
    });
});

describe('relationships', function () {
    test('belongs to resource', function () {
        $landingPage = LandingPage::factory()->create();

        expect($landingPage->resource)->toBeInstanceOf(Resource::class);
    });
});

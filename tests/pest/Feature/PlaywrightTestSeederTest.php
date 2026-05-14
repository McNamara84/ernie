<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Title;
use App\Models\User;
use Database\Seeders\PlaywrightTestSeeder;
use Illuminate\Support\Str;

uses()->group('seeders', 'playwright');

const PLAYWRIGHT_PUBLISHED_DOI = '10.1234/playwright-published';
const PLAYWRIGHT_REVIEW_DOI = '10.1234/playwright-qa';
const PLAYWRIGHT_LEGACY_REVIEW_DOI = '10.1234/playwright-review';

function loadPlaywrightResourceByDoi(string $doi): Resource
{
    /** @var Resource $resource */
    $resource = Resource::query()
        ->with(['titles.titleType', 'creators.creatorable', 'rights', 'descriptions.descriptionType', 'landingPage'])
        ->where('doi', $doi)
        ->firstOrFail();

    return $resource;
}

function loadPlaywrightResourceByTitle(string $title): Resource
{
    /** @var Resource $resource */
    $resource = Resource::query()
        ->with(['titles.titleType', 'creators.creatorable', 'rights', 'descriptions.descriptionType', 'landingPage'])
        ->whereHas('titles', fn ($query) => $query->where('value', $title))
        ->firstOrFail();

    return $resource;
}

function expectPlaywrightFixtureToBeComplete(Resource $resource, string $publicStatus): void
{
    expect($resource->isComplete())->toBeTrue()
        ->and($resource->publicStatus())->toBe($publicStatus)
        ->and($resource->rights)->toHaveCount(1)
        ->and(
            $resource->descriptions->contains(
                fn ($description) => $description->isAbstract() && trim((string) $description->value) !== ''
            )
        )->toBeTrue();
}

it('seeds complete Playwright fixtures with the expected public statuses', function (): void {
    test()->seed(PlaywrightTestSeeder::class);

    $testUser = User::query()->where('email', 'test@example.com')->firstOrFail();
    $adminUser = User::query()->where('email', 'admin@example.com')->firstOrFail();
    $groupLeader = User::query()->where('email', 'groupleader@example.com')->firstOrFail();
    $curator = User::query()->where('email', 'curator@example.com')->firstOrFail();
    $beginner = User::query()->where('email', 'beginner@example.com')->firstOrFail();

    expect($testUser->role)->toBe(UserRole::ADMIN)
        ->and($adminUser->role)->toBe(UserRole::ADMIN)
        ->and($groupLeader->role)->toBe(UserRole::GROUP_LEADER)
        ->and($curator->role)->toBe(UserRole::CURATOR)
        ->and($beginner->role)->toBe(UserRole::BEGINNER);

    $published = loadPlaywrightResourceByDoi(PLAYWRIGHT_PUBLISHED_DOI);
    $review = loadPlaywrightResourceByDoi(PLAYWRIGHT_REVIEW_DOI);
    $noLandingPage = loadPlaywrightResourceByTitle('Playwright: Curation Resource (no landing page)');
    $curation = loadPlaywrightResourceByTitle('Playwright: Curation Resource (no DOI)');

    expectPlaywrightFixtureToBeComplete($published, 'published');
    expectPlaywrightFixtureToBeComplete($review, 'review');
    expectPlaywrightFixtureToBeComplete($noLandingPage, 'curation');
    expectPlaywrightFixtureToBeComplete($curation, 'curation');

    expect($published->landingPage?->slug)->toBe('playwright-published')
        ->and($published->landingPage?->is_published)->toBeTrue()
        ->and($review->landingPage?->slug)->toBe('playwright-review')
        ->and($review->landingPage?->is_published)->toBeFalse()
        ->and($noLandingPage->landingPage)->toBeNull()
        ->and($curation->landingPage?->slug)->toBe('playwright-curation')
        ->and($curation->doi)->toBeNull()
        ->and(Resource::query()->where('doi', 'like', '10.5880/testdata.%')->exists())->toBeTrue();
});

it('repairs legacy and incomplete Playwright fixtures idempotently', function (): void {
    $published = Resource::factory()->withDoi(PLAYWRIGHT_PUBLISHED_DOI)->create();
    Title::factory()->create([
        'resource_id' => $published->id,
        'value' => 'Playwright: Published Resource',
    ]);
    ResourceCreator::factory()->create([
        'resource_id' => $published->id,
        'position' => 1,
    ]);
    LandingPage::create([
        'resource_id' => $published->id,
        'slug' => 'playwright-published',
        'template' => 'default_gfz',
        'is_published' => true,
        'published_at' => now(),
        'doi_prefix' => 'stale-prefix',
    ]);

    $legacyReview = Resource::factory()->withDoi(PLAYWRIGHT_LEGACY_REVIEW_DOI)->create();
    Title::factory()->create([
        'resource_id' => $legacyReview->id,
        'value' => 'Playwright: Review Resource',
    ]);
    ResourceCreator::factory()->create([
        'resource_id' => $legacyReview->id,
        'position' => 1,
    ]);
    LandingPage::create([
        'resource_id' => $legacyReview->id,
        'slug' => 'playwright-review',
        'template' => 'default_gfz',
        'is_published' => false,
        'published_at' => null,
        'preview_token' => Str::random(64),
    ]);

    $noLandingPage = Resource::factory()->create(['doi' => null]);
    Title::factory()->create([
        'resource_id' => $noLandingPage->id,
        'value' => 'Playwright: Curation Resource (no landing page)',
    ]);
    ResourceCreator::factory()->create([
        'resource_id' => $noLandingPage->id,
        'position' => 1,
    ]);

    $curation = Resource::factory()->create(['doi' => null]);
    Title::factory()->create([
        'resource_id' => $curation->id,
        'value' => 'Playwright: Curation Resource (no DOI)',
    ]);
    ResourceCreator::factory()->create([
        'resource_id' => $curation->id,
        'position' => 1,
    ]);
    LandingPage::create([
        'resource_id' => $curation->id,
        'slug' => 'playwright-curation',
        'template' => 'default_gfz',
        'is_published' => true,
        'published_at' => now(),
        'preview_token' => Str::random(64),
    ]);

    test()->seed(PlaywrightTestSeeder::class);
    test()->seed(PlaywrightTestSeeder::class);

    $repairedPublished = loadPlaywrightResourceByDoi(PLAYWRIGHT_PUBLISHED_DOI);
    $repairedReview = loadPlaywrightResourceByDoi(PLAYWRIGHT_REVIEW_DOI);
    $repairedNoLandingPage = loadPlaywrightResourceByTitle('Playwright: Curation Resource (no landing page)');
    $repairedCuration = loadPlaywrightResourceByTitle('Playwright: Curation Resource (no DOI)');

    expect($repairedPublished->id)->toBe($published->id)
        ->and($repairedPublished->landingPage?->doi_prefix)->toBe(PLAYWRIGHT_PUBLISHED_DOI);
    expectPlaywrightFixtureToBeComplete($repairedPublished, 'published');

    expect($repairedReview->id)->toBe($legacyReview->id)
        ->and(Resource::query()->where('doi', PLAYWRIGHT_LEGACY_REVIEW_DOI)->exists())->toBeFalse()
        ->and($repairedReview->titles->contains(fn ($title) => $title->value === 'Playwright: QA Resource'))->toBeTrue();
    expectPlaywrightFixtureToBeComplete($repairedReview, 'review');

    expect($repairedNoLandingPage->id)->toBe($noLandingPage->id);
    expectPlaywrightFixtureToBeComplete($repairedNoLandingPage, 'curation');

    expect($repairedCuration->id)->toBe($curation->id)
        ->and($repairedCuration->landingPage?->slug)->toBe('playwright-curation');
    expectPlaywrightFixtureToBeComplete($repairedCuration, 'curation');

    expect(User::query()->whereIn('email', [
        'test@example.com',
        'admin@example.com',
        'groupleader@example.com',
        'curator@example.com',
        'beginner@example.com',
    ])->count())->toBe(5)
        ->and(LandingPage::query()->whereIn('slug', [
            'playwright-published',
            'playwright-review',
            'playwright-curation',
        ])->count())->toBe(3)
        ->and(Resource::query()->where('doi', PLAYWRIGHT_PUBLISHED_DOI)->count())->toBe(1)
        ->and(Resource::query()->where('doi', PLAYWRIGHT_REVIEW_DOI)->count())->toBe(1);
});

it('replaces blank abstract fixtures with a non-empty abstract', function (): void {
    $abstractType = DescriptionType::query()->firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract', 'is_active' => true, 'is_elmo_active' => true],
    );

    $resource = Resource::factory()->withDoi(PLAYWRIGHT_PUBLISHED_DOI)->create();
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Playwright: Published Resource',
    ]);
    ResourceCreator::factory()->create([
        'resource_id' => $resource->id,
        'position' => 1,
    ]);
    LandingPage::create([
        'resource_id' => $resource->id,
        'slug' => 'playwright-published',
        'template' => 'default_gfz',
        'is_published' => true,
        'published_at' => now(),
        'doi_prefix' => 'stale-prefix',
    ]);
    Description::query()->create([
        'resource_id' => $resource->id,
        'description_type_id' => $abstractType->id,
        'value' => '   ',
    ]);

    test()->seed(PlaywrightTestSeeder::class);

    $reloaded = loadPlaywrightResourceByDoi(PLAYWRIGHT_PUBLISHED_DOI);

    expect($reloaded->descriptions->contains(fn ($description) => $description->isAbstract() && trim((string) $description->value) !== ''))
        ->toBeTrue()
        ->and($reloaded->isComplete())->toBeTrue();
});
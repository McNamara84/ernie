<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\EditorDraftSaveIntent;
use App\Enums\UserRole;
use App\Http\Requests\StoreResourceRequest;
use App\Models\ContributorType;
use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use App\Policies\ResourcePolicy;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\Editor\EditorResourceSaveService;
use Illuminate\Auth\Access\AuthorizationException;

/** @return array<string, mixed> */
function doiChangePayload(Resource $resource, string $doi, bool $draft, array $overrides = []): array
{
    $payload = [
        'resourceId' => $resource->id,
        'doi' => $doi,
        'year' => 2025,
        'resourceType' => test()->resourceType->id,
        'accessLevel' => AccessLevel::OPEN->value,
        'titles' => [
            ['title' => 'Updated DOI resource', 'titleType' => 'main-title'],
        ],
        'licenses' => [test()->right->identifier],
        'authors' => [
            [
                'type' => 'person',
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'isContact' => false,
                'position' => 0,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            ['descriptionType' => 'abstract', 'description' => 'A complete abstract.'],
        ],
        'datacenter_id' => test()->datacenter->id,
    ];

    if ($draft) {
        $payload['intent'] = EditorDraftSaveIntent::AUTOSAVE->value;
    }

    return array_merge($payload, $overrides);
}

beforeEach(function (): void {
    $this->datacenter = Datacenter::factory()->create();
    $this->resourceType = ResourceType::factory()->create();
    $this->right = Right::factory()->create();

    TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title'],
    );
    DescriptionType::firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract'],
    );
    ContributorType::firstOrCreate(
        ['slug' => 'ContactPerson'],
        ['name' => 'Contact Person', 'category' => 'person'],
    );
});

it('persists an authorized DOI replacement through both editor save paths', function (string $endpoint, bool $draft): void {
    $user = User::factory()->curator()->create();
    $resource = Resource::factory()->withDoi('10.5880/old.001')->create();
    $landingPage = LandingPage::factory()->for($resource)->draft()->withDoi('10.5880/old.001')->create();

    $this->actingAs($user)
        ->postJson($endpoint, doiChangePayload($resource, '10.5880/new.001', $draft))
        ->assertOk();

    expect($resource->fresh()->doi)->toBe('10.5880/new.001')
        ->and($landingPage->fresh()->doi_prefix)->toBe('10.5880/new.001');
})->with([
    'validated save' => ['/editor/resources', false],
    'draft autosave' => ['/editor/resources/draft', true],
]);

it('persists an authorized DOI removal and synchronizes its draft landing page', function (string $endpoint, bool $draft): void {
    $user = User::factory()->groupLeader()->create();
    $resource = Resource::factory()->withDoi('10.5880/remove.001')->create();
    $landingPage = LandingPage::factory()->for($resource)->draft()->withDoi('10.5880/remove.001')->create();

    $payload = doiChangePayload($resource, '', $draft);

    $this->actingAs($user)
        ->postJson($endpoint, $payload)
        ->assertOk();

    expect($resource->fresh()->doi)->toBeNull()
        ->and($landingPage->fresh()->doi_prefix)->toBeNull();
})->with([
    'validated save' => ['/editor/resources', false],
    'draft autosave' => ['/editor/resources/draft', true],
]);

it('rejects replacing or removing a published DOI for non-admin roles on both save paths', function (
    UserRole $role,
    string $submittedDoi,
    string $endpoint,
    bool $draft,
): void {
    $user = User::factory()->create(['role' => $role]);
    $resource = Resource::factory()->withDoi('10.5880/published.001')->create();
    $landingPage = LandingPage::factory()->for($resource)->published()->withDoi('10.5880/published.001')->create();

    $this->actingAs($user)
        ->postJson($endpoint, doiChangePayload($resource, $submittedDoi, $draft))
        ->assertForbidden()
        ->assertJsonPath('message', StoreResourceRequest::DOI_CHANGE_UNAUTHORIZED_MESSAGE);

    expect($resource->fresh()->doi)->toBe('10.5880/published.001')
        ->and($landingPage->fresh()->doi_prefix)->toBe('10.5880/published.001');
})->with([
    'curator replaces via validated save' => [UserRole::CURATOR, '10.5880/replacement.001', '/editor/resources', false],
    'curator removes via draft autosave' => [UserRole::CURATOR, '', '/editor/resources/draft', true],
    'group leader removes via validated save' => [UserRole::GROUP_LEADER, '', '/editor/resources', false],
    'group leader replaces via draft autosave' => [UserRole::GROUP_LEADER, '10.5880/replacement.001', '/editor/resources/draft', true],
]);

it('rejects a beginner changing a DOI before publication', function (string $endpoint, bool $draft): void {
    $user = User::factory()->beginner()->create();
    $resource = Resource::factory()->withDoi('10.5880/beginner.001')->create();
    $landingPage = LandingPage::factory()->for($resource)->draft()->withDoi('10.5880/beginner.001')->create();

    $this->actingAs($user)
        ->postJson($endpoint, doiChangePayload($resource, '10.5880/not-allowed.001', $draft))
        ->assertForbidden();

    expect($resource->fresh()->doi)->toBe('10.5880/beginner.001')
        ->and($landingPage->fresh()->doi_prefix)->toBe('10.5880/beginner.001');
})->with([
    'validated save' => ['/editor/resources', false],
    'draft autosave' => ['/editor/resources/draft', true],
]);

it('allows authorized roles to enter the first DOI after the landing page is public', function (
    UserRole $role,
    string $endpoint,
    bool $draft,
): void {
    $user = User::factory()->create(['role' => $role]);
    $resource = Resource::factory()->create(['doi' => null]);
    $landingPage = LandingPage::factory()->for($resource)->published()->create(['doi_prefix' => null]);

    $this->actingAs($user)
        ->postJson($endpoint, doiChangePayload($resource, '10.5880/first-public.001', $draft))
        ->assertOk()
        ->assertJsonPath('resource.publicStatus', 'published')
        ->assertJsonPath('resource.canEditDoi', false);

    expect($resource->fresh()->doi)->toBe('10.5880/first-public.001')
        ->and($landingPage->fresh()->doi_prefix)->toBe('10.5880/first-public.001');
})->with([
    'curator via validated save' => [UserRole::CURATOR, '/editor/resources', false],
    'curator via draft autosave' => [UserRole::CURATOR, '/editor/resources/draft', true],
    'group leader via validated save' => [UserRole::GROUP_LEADER, '/editor/resources', false],
    'group leader via draft autosave' => [UserRole::GROUP_LEADER, '/editor/resources/draft', true],
]);

it('rejects a beginner entering the first DOI on a persisted DOI-less resource', function (string $endpoint, bool $draft): void {
    $user = User::factory()->beginner()->create();
    $resource = Resource::factory()->create(['doi' => null]);
    $landingPage = LandingPage::factory()->for($resource)->published()->create(['doi_prefix' => null]);

    $this->actingAs($user)
        ->postJson($endpoint, doiChangePayload($resource, '10.5880/beginner-first.001', $draft))
        ->assertForbidden();

    expect($resource->fresh()->doi)->toBeNull()
        ->and($landingPage->fresh()->doi_prefix)->toBeNull();
})->with([
    'validated save' => ['/editor/resources', false],
    'draft autosave' => ['/editor/resources/draft', true],
]);

it('re-checks DOI authorization inside the mutation transaction after publication', function (): void {
    $user = User::factory()->curator()->create();
    $resource = Resource::factory()->withDoi('10.5880/race-old.001')->create();
    $landingPage = LandingPage::factory()->for($resource)->draft()->withDoi('10.5880/race-old.001')->create();
    $payload = doiChangePayload($resource, '10.5880/race-new.001', false);

    expect($user->can('changeDoi', [$resource->fresh('landingPage'), '10.5880/race-new.001']))->toBeTrue();

    $landingPage->publish();

    expect(fn () => app(EditorResourceSaveService::class)->saveValidated($payload, $user))
        ->toThrow(AuthorizationException::class, ResourcePolicy::DOI_CHANGE_UNAUTHORIZED_MESSAGE);

    expect($resource->fresh()->doi)->toBe('10.5880/race-old.001')
        ->and($landingPage->fresh()->doi_prefix)->toBe('10.5880/race-old.001');
});

it('prepares citation labels before locking and re-checking the DOI mutation', function (): void {
    $user = User::factory()->curator()->create();
    $resource = Resource::factory()->withDoi('10.5880/preparation-old.001')->create();
    $landingPage = LandingPage::factory()->for($resource)->draft()->withDoi('10.5880/preparation-old.001')->create();

    $citationLabels = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationLabels->shouldReceive('resolveBestEffortBatchForStorage')
        ->once()
        ->andReturnUsing(function (array $relatedIdentifiers) use ($landingPage): array {
            $landingPage->publish();

            return $relatedIdentifiers;
        });
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationLabels);

    $payload = doiChangePayload($resource, '10.5880/preparation-new.001', false, [
        'relatedIdentifiers' => [
            [
                'identifier' => '10.5880/related.001',
                'identifierType' => 'DOI',
                'relationType' => 'Cites',
            ],
        ],
    ]);

    expect(fn () => app(EditorResourceSaveService::class)->saveValidated($payload, $user))
        ->toThrow(AuthorizationException::class, ResourcePolicy::DOI_CHANGE_UNAUTHORIZED_MESSAGE);

    expect($resource->fresh()->doi)->toBe('10.5880/preparation-old.001')
        ->and($landingPage->fresh()->is_published)->toBeFalse()
        ->and($landingPage->fresh()->doi_prefix)->toBe('10.5880/preparation-old.001');
});

it('allows an admin to replace or remove a published DOI through both save paths', function (
    string $submittedDoi,
    ?string $expectedDoi,
    string $endpoint,
    bool $draft,
): void {
    $user = User::factory()->admin()->create();
    $resource = Resource::factory()->withDoi('10.5880/admin.001')->create();
    $landingPage = LandingPage::factory()->for($resource)->published()->withDoi('10.5880/admin.001')->create();

    $this->actingAs($user)
        ->postJson($endpoint, doiChangePayload($resource, $submittedDoi, $draft))
        ->assertOk();

    expect($resource->fresh()->doi)->toBe($expectedDoi)
        ->and($landingPage->fresh()->doi_prefix)->toBe($expectedDoi);
})->with([
    'replace via validated save' => ['10.5880/admin-new.001', '10.5880/admin-new.001', '/editor/resources', false],
    'remove via draft autosave' => ['', null, '/editor/resources/draft', true],
]);

it('allows a normalized unchanged DOI while saving other metadata', function (string $endpoint, bool $draft): void {
    $user = User::factory()->beginner()->create();
    $resource = Resource::factory()->withDoi('10.5880/unchanged.001')->create();
    LandingPage::factory()->for($resource)->published()->withDoi('10.5880/unchanged.001')->create();

    $payload = doiChangePayload($resource, ' HTTPS://DOI.ORG/10.5880/UNCHANGED.001 ', $draft, [
        'titles' => [
            ['title' => 'Other metadata was updated', 'titleType' => 'main-title'],
        ],
    ]);

    $this->actingAs($user)
        ->postJson($endpoint, $payload)
        ->assertOk();

    expect($resource->fresh()->doi)->toBe('10.5880/unchanged.001')
        ->and($resource->titles()->first()?->value)->toBe('Other metadata was updated');
})->with([
    'validated save' => ['/editor/resources', false],
    'draft autosave' => ['/editor/resources/draft', true],
]);

it('treats an omitted DOI as unchanged while saving other metadata', function (string $endpoint, bool $draft): void {
    $user = User::factory()->beginner()->create();
    $resource = Resource::factory()->withDoi('10.5880/omitted.001')->create();
    LandingPage::factory()->for($resource)->published()->withDoi('10.5880/omitted.001')->create();

    $payload = doiChangePayload($resource, '10.5880/omitted.001', $draft, [
        'titles' => [
            ['title' => 'Updated without a DOI field', 'titleType' => 'main-title'],
        ],
    ]);
    unset($payload['doi']);

    $this->actingAs($user)
        ->postJson($endpoint, $payload)
        ->assertOk();

    expect($resource->fresh()->doi)->toBe('10.5880/omitted.001')
        ->and($resource->titles()->first()?->value)->toBe('Updated without a DOI field');
})->with([
    'validated save' => ['/editor/resources', false],
    'draft autosave' => ['/editor/resources/draft', true],
]);

it('keeps first-time DOI entry available when creating a resource', function (): void {
    $user = User::factory()->beginner()->create();

    $response = $this->actingAs($user)
        ->postJson('/editor/resources/draft', [
            'doi' => '10.5880/first-entry.001',
            'titles' => [
                ['title' => 'First DOI entry', 'titleType' => 'main-title'],
            ],
        ])
        ->assertCreated();

    expect(Resource::query()->findOrFail($response->json('resource.id'))->doi)
        ->toBe('10.5880/first-entry.001');
});

it('leaves malformed DOI data to validation instead of returning a permission error', function (): void {
    $user = User::factory()->curator()->create();
    $resource = Resource::factory()->create();

    $this->actingAs($user)
        ->postJson('/editor/resources/draft', [
            'resourceId' => $resource->id,
            'doi' => ['not-a-string'],
            'titles' => [
                ['title' => 'Malformed DOI', 'titleType' => 'main-title'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['doi']);
});

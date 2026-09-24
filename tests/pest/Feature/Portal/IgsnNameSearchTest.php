<?php

declare(strict_types=1);

use App\Enums\PortalScope;
use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\Description;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\IgsnMetadata;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\IgsnPortalFacetService;
use App\Services\PortalMapService;
use App\Services\PortalSearchService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->igsnType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => PortalScope::PHYSICAL_SAMPLE_RESOURCE_TYPE,
    ]);
    $this->doiType = ResourceType::factory()->create(['name' => 'Dataset', 'slug' => 'dataset']);
    $this->mainTitleType = TitleType::factory()->create(['name' => 'Main Title', 'slug' => 'main-title']);
});

function createIgsnNameSearchResource(ResourceType $type, TitleType $titleType, string $title = 'Unrelated sample', bool $published = true): Resource
{
    $resource = Resource::factory()->create(['resource_type_id' => $type->id]);
    Title::factory()->create([
        'resource_id' => $resource->id,
        'title_type_id' => $titleType->id,
        'value' => $title,
    ]);
    LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'is_published' => $published,
        'published_at' => $published ? now() : null,
    ]);

    return $resource;
}

/** @return list<int> */
function igsnNameSearchIds(string $query): array
{
    return collect(app(PortalSearchService::class)->search([
        'portal_scope' => PortalScope::IGSN->value,
        'query' => $query,
    ])->items())->pluck('id')->all();
}

it('finds creator snapshots, contributor persons and institutions through normalized names', function (): void {
    $creatorResource = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    $person = Person::factory()->create(['given_name' => 'Stale', 'family_name' => 'Global']);
    ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $creatorResource->id,
        'given_name_snapshot' => 'Peter',
        'family_name_snapshot' => 'Hans',
        'name_snapshot' => 'Hans, Peter',
    ]);

    $contributorResource = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    $contributor = Person::factory()->create(['given_name' => 'Iggy', 'family_name' => 'Contributor']);
    ResourceContributor::factory()->forPerson($contributor)->create(['resource_id' => $contributorResource->id]);
    $institution = Institution::factory()->create(['name' => 'GFZ Sample Laboratory']);
    ResourceContributor::factory()->forInstitution($institution)->create(['resource_id' => $contributorResource->id]);

    expect(igsnNameSearchIds('HansPeter'))->toBe([$creatorResource->id])
        ->and(igsnNameSearchIds('peter hans'))->toBe([$creatorResource->id])
        ->and(igsnNameSearchIds('iggy contr'))->toBe([$contributorResource->id])
        ->and(igsnNameSearchIds('sample laboratory'))->toBe([$contributorResource->id])
        ->and(igsnNameSearchIds('Stale Global'))->toBe([]);
});

it('matches multiple wildcards within one name without joining separate people', function (): void {
    $matching = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    ResourceContributor::factory()->forPerson(Person::factory()->create([
        'given_name' => 'Anna', 'family_name' => 'HansPeter',
    ]))->create(['resource_id' => $matching->id]);

    $split = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    ResourceContributor::factory()->forPerson(Person::factory()->create([
        'given_name' => 'One', 'family_name' => 'Hans',
    ]))->create(['resource_id' => $split->id]);
    ResourceContributor::factory()->forPerson(Person::factory()->create([
        'given_name' => 'Peter', 'family_name' => 'Two',
    ]))->create(['resource_id' => $split->id]);

    expect(igsnNameSearchIds('H*ns*Peter'))->toBe([$matching->id]);
});

it('finds partial local accession numbers and sample names with wildcard patterns', function (): void {
    $resource = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    AlternateIdentifier::query()->create([
        'resource_id' => $resource->id,
        'type' => 'Local accession number',
        'value' => 'Geo-Access-12',
        'position' => 0,
    ]);
    AlternateIdentifier::query()->create([
        'resource_id' => $resource->id,
        'type' => 'Local sample name',
        'value' => 'Basalt Sample A12',
        'position' => 1,
    ]);

    expect(igsnNameSearchIds('access'))->toBe([$resource->id])
        ->and(igsnNameSearchIds('G*o*12'))->toBe([$resource->id])
        ->and(igsnNameSearchIds('bas*sample*a12'))->toBe([$resource->id]);
});

it('does not join separate sample names to satisfy one wildcard pattern', function (): void {
    $resource = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    foreach (['Geo Sample', '12'] as $position => $value) {
        AlternateIdentifier::query()->create([
            'resource_id' => $resource->id,
            'type' => 'Local sample name',
            'value' => $value,
            'position' => $position,
        ]);
    }

    expect(igsnNameSearchIds('Geo*12'))->toBe([]);
});

it('keeps SQL wildcards literal and excludes private email values from public name search', function (): void {
    $resource = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    AlternateIdentifier::query()->create([
        'resource_id' => $resource->id,
        'type' => 'Local sample name',
        'value' => 'Rock_%!_12',
        'position' => 0,
    ]);
    ResourceContributor::factory()->forPerson(Person::factory()->create([
        'given_name' => 'Unrelated', 'family_name' => 'Researcher',
    ]))->create(['resource_id' => $resource->id, 'email' => 'hidden-person@example.test']);

    expect(igsnNameSearchIds('%!'))->toBe([$resource->id])
        ->and(igsnNameSearchIds('Rock_A'))->toBe([])
        ->and(igsnNameSearchIds('hidden-person'))->toBe([])
        ->and(DB::table('resource_party_name_terms')->where('resource_id', $resource->id)->pluck('term')->implode(' '))
        ->not->toContain('hidden-person');
});

it('keeps IGSN scope and publication boundaries and treats * alone as unfiltered', function (): void {
    $published = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType, 'Geo Rock 12');
    createIgsnNameSearchResource($this->igsnType, $this->mainTitleType, 'Geo Rock 12', false);
    createIgsnNameSearchResource($this->doiType, $this->mainTitleType, 'Geo Rock 12');

    expect(igsnNameSearchIds('Geo*12'))->toBe([$published->id])
        ->and(igsnNameSearchIds('*'))->toBe([$published->id])
        ->and(app(PortalSearchService::class)->count([
            'portal_scope' => PortalScope::IGSN->value,
            'query' => 'Geo*12',
        ]))->toBe(1);
});

it('uses the same wildcard filter for search results, count, facets and map features', function (): void {
    config(['portal_map.enabled' => true, 'portal_map.igsn_material_visualization_enabled' => true]);
    $matching = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    $other = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    foreach ([[$matching, 'Core', 'Geo-12'], [$other, 'Drill', 'Other-99']] as [$resource, $sampleType, $name]) {
        IgsnMetadata::query()->create(['resource_id' => $resource->id, 'sample_type' => $sampleType, 'material' => 'Rock']);
        AlternateIdentifier::query()->create([
            'resource_id' => $resource->id,
            'type' => 'Local sample name',
            'value' => $name,
            'position' => 0,
        ]);
        GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $resource->id]);
    }

    $filters = ['portal_scope' => PortalScope::IGSN->value, 'query' => 'Geo*12'];
    $search = app(PortalSearchService::class);
    $facets = app(IgsnPortalFacetService::class)->getFacets($filters);
    $map = app(PortalMapService::class)->getMapData($filters, [
        'north' => 54.0, 'south' => 50.0, 'east' => 16.0, 'west' => 10.0, 'width' => 1000, 'height' => 700,
    ], 12, PortalScope::IGSN);

    expect(collect($search->search($filters)->items())->pluck('id')->all())->toBe([$matching->id])
        ->and($search->count($filters))->toBe(1)
        ->and(collect($facets['sampleTypes'])->pluck('count', 'value')->all())->toBe(['Core' => 1])
        ->and(collect($map['features'])->pluck('resource.id')->all())->toBe([$matching->id]);
});

it('preserves title, description, subject and identity-identifier search with wildcards', function (): void {
    $resource = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType, 'Basalt Field Sample');
    Description::factory()->create(['resource_id' => $resource->id, 'value' => 'Deep ocean sample 123']);
    Subject::factory()->create(['resource_id' => $resource->id, 'value' => 'Geochemistry 2026']);
    $identifierType = IdentifierType::query()->create(['slug' => 'IGSN', 'name' => 'IGSN']);
    $identical = RelationType::query()->create(['slug' => 'IsIdenticalTo', 'name' => 'Is Identical To']);
    $references = RelationType::query()->create(['slug' => 'References', 'name' => 'References']);
    RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier_type_id' => $identifierType->id,
        'relation_type_id' => $identical->id,
        'identifier' => '10273/GFBNO7002EXZ3001',
    ]);
    RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier_type_id' => $identifierType->id,
        'relation_type_id' => $references->id,
        'identifier' => '10273/CITEDONLY2001',
    ]);

    expect(igsnNameSearchIds('Bas*Sample'))->toBe([$resource->id])
        ->and(igsnNameSearchIds('ocean*123'))->toBe([$resource->id])
        ->and(igsnNameSearchIds('geo*2026'))->toBe([$resource->id])
        ->and(igsnNameSearchIds('GFBNO*3001'))->toBe([$resource->id])
        ->and(igsnNameSearchIds('https://igsn.org/GFBNO*3001'))->toBe([$resource->id])
        ->and(igsnNameSearchIds('CITED*2001'))->toBe([]);
});

it('expands wildcarded identity suffixes across the 10273 and 10.60510 aliases', function (): void {
    $identifierType = IdentifierType::query()->create(['slug' => 'IGSN', 'name' => 'IGSN']);
    $identical = RelationType::query()->create(['slug' => 'IsIdenticalTo', 'name' => 'Is Identical To']);
    $legacy = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    $modern = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);

    foreach ([
        [$legacy, '10273/GFBNO7002EXZ3001'],
        [$modern, '10.60510/ICDP1234ABC9002'],
    ] as [$resource, $identifier]) {
        RelatedIdentifier::query()->create([
            'resource_id' => $resource->id,
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $identical->id,
            'identifier' => $identifier,
        ]);
    }

    expect(igsnNameSearchIds('10.60510/GFBNO*3001'))->toBe([$legacy->id])
        ->and(igsnNameSearchIds('https://doi.org/10.60510/GFBNO*3001'))->toBe([$legacy->id])
        ->and(igsnNameSearchIds('10273/ICDP*9002'))->toBe([$modern->id]);
});

it('refreshes and removes separate name terms when resource parties change', function (): void {
    $resource = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    $contributor = ResourceContributor::factory()->forPerson(Person::factory()->create([
        'given_name' => 'Before', 'family_name' => 'Contributor',
    ]))->create(['resource_id' => $resource->id]);

    expect(igsnNameSearchIds('Before'))->toBe([$resource->id]);

    $newPerson = Person::factory()->create(['given_name' => 'After', 'family_name' => 'Contributor']);
    $contributor->update(['contributorable_id' => $newPerson->id]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(igsnNameSearchIds('Before'))->toBe([])
        ->and(igsnNameSearchIds('After'))->toBe([$resource->id]);

    $contributor->delete();
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(igsnNameSearchIds('After'))->toBe([])
        ->and(DB::table('resource_party_name_terms')->where('resource_id', $resource->id)->count())->toBe(0);
});

it('shows public party roles and the exact matching sample-name values in IGSN result rows', function (): void {
    $resource = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType, 'Unique specimen title');
    $person = Person::factory()->create(['given_name' => 'Peter', 'family_name' => 'Hans']);
    ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'is_contact' => true,
        'email' => 'private-author@example.test',
    ]);
    $contributor = ResourceContributor::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'email' => 'private-contributor@example.test',
    ]);
    $researcher = ContributorType::query()->create(['name' => 'Researcher', 'slug' => 'Researcher']);
    $contact = ContributorType::query()->create(['name' => 'Contact Person', 'slug' => 'ContactPerson']);
    $contributor->contributorTypes()->sync([$researcher->id, $contact->id]);
    foreach ([
        ['Local accession number', 'HansPeter-12'],
        ['Local sample name', 'HansPeter Sample'],
        ['Local sample name', 'Unrelated sample name'],
    ] as $position => [$type, $value]) {
        AlternateIdentifier::query()->create([
            'resource_id' => $resource->id,
            'type' => $type,
            'value' => $value,
            'position' => $position,
        ]);
    }

    $this->get('/igsn-search?q=Hans*Peter')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('resources', 1)
            ->where('resources.0.id', $resource->id)
            ->where('resources.0.searchMatches', [[
                'display_value' => 'Hans, Peter',
                'matched_field' => 'name',
                'roles' => ['contact_person', 'author', 'contributor'],
            ]])
            ->where('resources.0.sampleNameMatches', [
                ['label' => 'Local accession number', 'display_value' => 'HansPeter-12'],
                ['label' => 'Local sample name', 'display_value' => 'HansPeter Sample'],
            ]));

    $this->get('/igsn-search?q=Unique')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('resources', 1)
            ->where('resources.0.searchMatches', [])
            ->where('resources.0.sampleNameMatches', []));

    $this->get('/igsn-search?q=*')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('resources', 1)
            ->where('resources.0.searchMatches', [])
            ->where('resources.0.sampleNameMatches', []));
});

it('shows the matching contributor institution in the IGSN result row', function (): void {
    $resource = createIgsnNameSearchResource($this->igsnType, $this->mainTitleType);
    $institution = Institution::factory()->create(['name' => 'GFZ Rock Institute']);
    ResourceContributor::factory()->forInstitution($institution)->create(['resource_id' => $resource->id]);

    $this->get('/igsn-search?q=Rock*Institute')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('resources', 1)
            ->where('resources.0.searchMatches', [[
                'display_value' => 'GFZ Rock Institute',
                'matched_field' => 'name',
                'roles' => ['contributor'],
            ]])
            ->where('resources.0.sampleNameMatches', []));
});

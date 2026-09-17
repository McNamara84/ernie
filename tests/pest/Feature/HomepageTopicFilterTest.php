<?php

declare(strict_types=1);

use App\Enums\PortalScope;
use App\Enums\ScienceTopic;
use App\Models\Datacenter;
use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\KeywordSuggestionService;
use App\Services\PortalSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/** Representative GCMD labels verified against the vocabulary dated 2026-09-02. */
function homepageTopicExamples(): array
{
    return [
        'atmosphere' => 'ATMOSPHERE', 'agriculture' => 'AGRICULTURE',
        'biosphere' => 'BIOSPHERE', 'climate-science' => 'CLIMATE INDICATORS',
        'cryosphere' => 'CRYOSPHERE', 'geochemistry' => 'GEOCHEMISTRY',
        'geodetics' => 'GEODETICS', 'geomagnetism' => 'GEOMAGNETISM',
        'geothermics' => 'GEOTHERMAL DYNAMICS', 'gravity' => 'GRAVITY/GRAVITATIONAL FIELD',
        'human-dimensions' => 'HUMAN DIMENSIONS', 'hydrology' => 'HYDROLOGICAL ADVISORIES',
        'land-surface' => 'LAND SURFACE', 'natural-hazards' => 'HAZARDS MANAGEMENT',
        'oceans' => 'OCEANS', 'paleoclimate' => 'PALEOCLIMATE', 'solid-earth' => 'SOLID EARTH',
        'rocks-minerals' => 'ROCKS/MINERALS/CRYSTALS',
        'sun-earth-interactions' => 'SUN-EARTH INTERACTIONS', 'volcanism' => 'VOLCANIC ACTIVITY',
    ];
}

function homepageTopicNode(string $slug, string $label, array $children = []): array
{
    return [
        'id' => 'https://example.test/'.$slug, 'text' => $label,
        'scheme' => 'NASA/GCMD Earth Science Keywords', 'language' => 'en',
        'schemeURI' => 'https://example.test/science', 'description' => '', 'children' => $children,
    ];
}

function homepageTopicResource(string $title = 'Research observations', string $type = 'dataset', bool $published = true): Resource
{
    $resource = Resource::factory()->create([
        'resource_type_id' => ResourceType::firstOrCreate(['slug' => $type], ['name' => $type])->id,
    ]);
    Title::factory()->create([
        'resource_id' => $resource->id, 'value' => $title,
        'title_type_id' => TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title'])->id,
    ]);
    LandingPage::factory()->create(['resource_id' => $resource->id, 'is_published' => $published]);

    return $resource;
}

function homepageTopicSubject(Resource $resource, string $slug, array $attributes = []): void
{
    Subject::factory()->create([
        'resource_id' => $resource->id, 'value' => 'MEASUREMENTS',
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
        'value_uri' => 'https://example.test/'.$slug.'/leaf', ...$attributes,
    ]);
}

function homepageTopicMapQuery(array $extra = []): array
{
    return [
        'viewport' => ['north' => 54, 'south' => 50, 'east' => 16, 'west' => 10, 'width' => 1000, 'height' => 700],
        'zoom' => 18, ...$extra,
    ];
}

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('local');
    config(['bot_protection.enabled' => false, 'portal_map.enabled' => true]);
    $nodes = [];
    foreach ([...homepageTopicExamples(), 'marine-biology' => 'MARINE BIOLOGY'] as $slug => $label) {
        $nodes[] = homepageTopicNode($slug, $label, [homepageTopicNode($slug.'/leaf', 'MEASUREMENTS')]);
    }
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'data' => [homepageTopicNode('root', 'Science Keywords', $nodes)],
    ], JSON_THROW_ON_ERROR));
});

it('filters every homepage topic and excludes drafts and IGSN samples', function (string $slug): void {
    $topic = ScienceTopic::from($slug);
    $positive = homepageTopicResource();
    $draft = homepageTopicResource(published: false);
    $sample = homepageTopicResource(type: 'physical-object');
    $negative = homepageTopicResource();
    foreach ([$positive, $draft, $sample] as $resource) {
        if ($topic->scienceKeywordPattern() !== null) {
            homepageTopicSubject($resource, $slug);
        } elseif ($topic === ScienceTopic::Archaeobotany) {
            homepageTopicSubject($resource, '', ['value' => ' Archaeobotany ', 'value_uri' => null, 'subject_scheme' => null]);
        } elseif ($topic === ScienceTopic::ScientificDrilling) {
            $resource->update(['datacenter_id' => Datacenter::firstOrCreate(['name' => 'SDDB Scientific Drilling Database'])->id]);
        } else {
            $text = match ($topic) {
                ScienceTopic::Modeling => 'Modeling methods',
                ScienceTopic::RemoteSensing => 'Remote sensing methods',
                ScienceTopic::Seismology => 'Seismic measurements',
            };
            $resource->titles()->update(['value' => $text]);
        }
    }

    // Same words in an unrelated scheme must not masquerade as GCMD metadata.
    homepageTopicSubject($negative, $slug, ['subject_scheme' => 'Other Vocabulary']);
    $filters = ['portal_scope' => 'doi', 'topic' => $slug];
    $service = app(PortalSearchService::class);
    expect($service->search($filters)->pluck('id')->all())->toBe([$positive->id])
        ->and($service->count($filters))->toBe(1);

    $this->get('/doi-search?topic='.$slug)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal')->has('resources', 1)
        ->where('filters.topic.slug', $slug)->where('filters.topic.label', $topic->label()));
})->with(array_map(static fn (ScienceTopic $topic): array => [$topic->value], ScienceTopic::cases()));

it('unions matching branches and intersects them with the other selected filters', function (): void {
    $land = homepageTopicResource('Land biology');
    $marine = homepageTopicResource('Marine biology');
    homepageTopicSubject($land, 'biosphere');
    homepageTopicSubject($marine, 'marine-biology');
    homepageTopicSubject($land, '', ['value' => 'selected', 'subject_scheme' => null, 'value_uri' => null]);
    $service = app(PortalSearchService::class);
    $filters = ['portal_scope' => 'doi', 'topic' => 'biosphere'];

    expect($service->search($filters)->pluck('id')->all())->toEqualCanonicalizing([$land->id, $marine->id]);
    expect($service->search([...$filters, 'free_keywords' => ['selected']])->pluck('id')->all())->toBe([$land->id]);
    expect($service->search([...$filters, 'query' => 'marine'])->pluck('id')->all())->toBe([$marine->id]);
    expect($service->count(['portal_scope' => 'doi', 'thesaurus_keywords' => [
        'https://example.test/biosphere', 'https://example.test/marine-biology',
    ]]))->toBe(0);
});

it('supports normalized legacy breadcrumbs without accepting conflicting URIs', function (): void {
    $legacy = homepageTopicResource();
    $conflict = homepageTopicResource();
    homepageTopicSubject($legacy, 'atmosphere', ['value_uri' => null, 'value' => 'ATMOSPHERE>MEASUREMENTS']);
    homepageTopicSubject($conflict, 'atmosphere', ['value_uri' => 'https://example.test/other', 'value' => 'ATMOSPHERE > MEASUREMENTS']);

    expect(app(PortalSearchService::class)->search(['portal_scope' => 'doi', 'topic' => 'atmosphere'])->pluck('id')->all())->toBe([$legacy->id]);
});

it('returns no results when a topic cannot resolve instead of broadening the query', function (string $slug): void {
    homepageTopicSubject(homepageTopicResource(), 'atmosphere');
    Storage::disk('local')->delete('gcmd-science-keywords.json');

    expect(app(PortalSearchService::class)->count(['portal_scope' => 'doi', 'topic' => $slug]))->toBe(0);
})->with(['atmosphere', 'not-a-topic']);

it('does not resolve free-text topics through the thesaurus', function (): void {
    expect(app(KeywordSuggestionService::class)->scienceTopicNodeIds(ScienceTopic::Seismology))->toBe([]);
});

it('preserves identical restrictions and separate cache entries for list count map and cluster members', function (): void {
    config(['bot_protection.enabled' => true]);
    $first = homepageTopicResource('First atmosphere dataset');
    $second = homepageTopicResource('Second atmosphere dataset');
    $unrelated = homepageTopicResource('Ocean dataset');
    homepageTopicSubject($first, 'atmosphere');
    homepageTopicSubject($second, 'atmosphere');
    homepageTopicSubject($unrelated, 'oceans');
    foreach ([$first, $second, $unrelated] as $resource) {
        GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $resource->id]);
    }

    $this->get('/doi-search?topic=atmosphere')->assertInertia(fn (Assert $page) => $page->has('resources', 2));
    $atmosphereCount = $this->getJson('/doi-search/count?topic=atmosphere')->assertOk()->assertJsonPath('total', 2);
    $oceanCount = $this->getJson('/doi-search/count?topic=oceans')->assertOk()->assertJsonPath('total', 1);
    expect($atmosphereCount->json('filter_fingerprint'))->not->toBe($oceanCount->json('filter_fingerprint'));
    $query = homepageTopicMapQuery(['topic' => 'atmosphere', 'include_extent' => 1]);
    $cluster = $this->getJson('/doi-search/map?'.http_build_query($query))->assertOk()
        ->assertJsonPath('meta.totalLocations', 2)->assertJsonPath('features.0.count', 2)->json('features.0.id');
    unset($query['zoom'], $query['include_extent']);
    $this->getJson('/doi-search/map/clusters/'.urlencode($cluster).'?'.http_build_query($query))
        ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'members');
    $this->getJson('/doi-search/map?'.http_build_query(homepageTopicMapQuery(['topic' => 'oceans', 'include_extent' => 1])))
        ->assertOk()->assertJsonPath('meta.totalLocations', 1)->assertJsonPath('features.0.kind', 'resource');
    $this->getJson('/doi-search/count?topic=atmosphere')->assertJsonPath('total', 2);
});

it('rejects malformed or unknown DOI topics on every public search surface', function (mixed $topic): void {
    $query = homepageTopicMapQuery(['topic' => $topic]);
    $this->getJson('/doi-search?'.http_build_query(['topic' => $topic]))->assertUnprocessable();
    $this->getJson('/doi-search/count?'.http_build_query(['topic' => $topic]))->assertUnprocessable();
    $this->getJson('/doi-search/map?'.http_build_query($query))->assertUnprocessable();
    unset($query['zoom']);
    $this->getJson('/doi-search/map/clusters/z18:1:1?'.http_build_query($query))->assertUnprocessable();
})->with(['unknown' => 'not-a-topic', 'array' => [['atmosphere']], 'object' => [['slug' => 'atmosphere']]]);

it('ignores DOI topic parameters in the IGSN portal', function (): void {
    homepageTopicResource(type: 'physical-object');
    homepageTopicResource();
    $this->get('/igsn-search?topic=atmosphere')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('resources', 1)->where('filters.topic', null));
    $this->getJson('/igsn-search/count?topic[]=unknown')->assertOk()->assertJsonPath('total', 1);
    expect(app(PortalSearchService::class)->count(['portal_scope' => PortalScope::IGSN->value, 'topic' => 'atmosphere']))->toBe(1);
});

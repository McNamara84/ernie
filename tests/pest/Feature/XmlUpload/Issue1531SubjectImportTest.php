<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\Editor\EditorDataTransformer;
use App\Services\LandingPageResourceTransformer;
use App\Services\ResourceStorageService;
use App\Services\Uploads\UploadedResourceDraftService;
use App\Services\VocabularyCacheService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SubjectDuplicateFixtures as F;

beforeEach(function (): void {
    F::fake();
    $this->actingAs(User::factory()->create());
});

it('imports the reported variants once and preserves them through an editor roundtrip', function (string $format, string $case): void {
    $subjects = array_map(static fn (array $row): array => array_filter([
        'subject' => $row['value'], 'subjectScheme' => $row['subject_scheme'], 'schemeUri' => $row['scheme_uri'],
        'valueUri' => $row['value_uri'], 'lang' => $row['language'],
    ], static fn ($value) => $value !== null), F::rows($case));
    if ($format === 'json') {
        $content = dataCiteJson(minimalAttributes(['subjects' => $subjects]));
    } else {
        $xml = new SimpleXMLElement('<resource xmlns="http://datacite.org/schema/kernel-4"><titles><title>Issue 1531</title></titles><publisher>GFZ</publisher><publicationYear>2026</publicationYear><resourceType resourceTypeGeneral="Dataset">Dataset</resourceType><subjects/></resource>');
        foreach ($subjects as $row) {
            $node = $xml->subjects->addChild('subject', htmlspecialchars($row['subject']));
            foreach (array_diff_key($row, ['subject' => true, 'lang' => true]) as $key => $value) {
                $node->addAttribute($key === 'schemeUri' ? 'schemeURI' : ($key === 'valueUri' ? 'valueURI' : $key), $value);
            }
        }
        $content = $xml->asXML();
    }
    $response = $this->postJson('/dashboard/upload-'.$format, ['file' => UploadedFile::fake()->createWithContent('issue-1531.'.$format, $content)])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    expect($resource->subjects)->toHaveCount(1);
    $keywords = app(EditorDataTransformer::class)->transformGcmdKeywords($resource);
    [$resource, $updated] = app(ResourceStorageService::class)->store(['resourceId' => $resource->id, 'year' => 2026,
        'titles' => [['title' => 'Issue 1531', 'titleType' => 'MainTitle']], 'authors' => [],
        'resourceType' => $resource->resource_type_id, 'gcmdKeywords' => [...$keywords, ...$keywords]], auth()->id());
    $resource->refresh();
    expect($updated)->toBeTrue()->and($resource->subjects)->toHaveCount(1)
        ->and(app(LandingPageResourceTransformer::class)->transform($resource)['subjects'])->toHaveCount(1)
        ->and(app(DataCiteJsonExporter::class)->export($resource)['data']['attributes']['subjects'])->toHaveCount(1);
    $export = app(DataCiteXmlExporter::class)->export($resource);
    expect(substr_count($export, '<subject '))->toBe(1);
})->with(['json', 'xml'])->with(['aquifers', 'instruments']);

it('retains unresolved GCMD subjects with an explicit legacy marker when the vocabulary is missing', function (): void {
    Storage::disk('local')->delete('gcmd-instruments.json');
    $json = dataCiteJson(minimalAttributes(['subjects' => [
        ['subject' => 'In Situ/Laboratory Instruments', 'subjectScheme' => 'GCMD Instruments'],
        ['subject' => 'In Situ/Laboratory Instruments', 'subjectScheme' => 'Instruments', 'valueUri' => F::INSTRUMENT],
    ]]));
    $response = $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent('unresolved.json', $json)])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    $keywords = app(EditorDataTransformer::class)->transformGcmdKeywords($resource);
    expect($resource->subjects)->toHaveCount(2)->and(array_column($keywords, 'isLegacy'))->toContain(true);
});

it('projects historical rows without changing storage and preserves translated variants', function (): void {
    $resource = F::resource('instruments');
    $resource->subjects()->create(array_replace(F::rows('instruments')[1], ['language' => 'de']));
    $before = $resource->subjects()->get()->toArray();
    $subjects = app(LandingPageResourceTransformer::class)->transform($resource)['subjects'];
    expect($subjects)->toHaveCount(2)->and($subjects[0]['value_uri'])->toBe(F::INSTRUMENT)
        ->and($resource->subjects()->get()->toArray())->toBe($before);
});

it('preserves a foreign concept URI even when its UUID matches a GCMD concept', function (): void {
    $foreign = str_replace('gcmd.earthdata.nasa.gov', 'example.org', F::AQUIFER);
    $subjects = array_map(static fn (string $uri): array => ['subject' => F::PATH,
        'subjectScheme' => 'Science Keywords', 'valueUri' => $uri], [F::AQUIFER, $foreign]);
    $response = $this->postJson('/dashboard/upload-json', ['file' => UploadedFile::fake()->createWithContent(
        'conflict.json', dataCiteJson(minimalAttributes(['subjects' => $subjects]))
    )])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    expect($resource->subjects)->toHaveCount(2)->and($resource->subjects->pluck('value_uri')->all())->toContain($foreign, F::AQUIFER);
});

it('recomputes cached landing page identities after vocabulary invalidation', function (): void {
    $resource = F::resource('instruments');
    $page = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $cache = app(LandingPageRenderDataCacheService::class);
    $render = static fn (): array => ['template' => 'default_gfz', 'props' => app(LandingPageResourceTransformer::class)->transform($resource->fresh())];
    expect($cache->remember($page, $render)['props']['subjects'])->toHaveCount(1);
    Storage::disk('local')->delete('gcmd-instruments.json');
    app(VocabularyCacheService::class)->invalidateVocabularyCache(CacheKey::GCMD_INSTRUMENTS);
    expect($cache->remember($page, $render)['props']['subjects'])->toHaveCount(2);
});

it('merges variants across compatible draft inputs and preserves translations on direct editor saves', function (string $case): void {
    $keywords = F::keywords($case);
    $resource = app(UploadedResourceDraftService::class)->storeFromPayload([
        'gcmdKeywords' => [$keywords[0]], 'mslKeywords' => [$keywords[1]],
        'year' => 2026, 'titles' => [['title' => 'Issue 1531', 'titleType' => 'MainTitle']],
    ], 'issue-1531.json', auth()->id());
    expect($resource->subjects)->toHaveCount(1);
    $translated = array_replace($keywords[1], ['language' => 'de']);
    [$saved, $updated] = app(ResourceStorageService::class)->store([
        'resourceId' => $resource->id, 'year' => 2026, 'resourceType' => $resource->resource_type_id,
        'titles' => [['title' => 'Issue 1531', 'titleType' => 'MainTitle']], 'authors' => [],
        'gcmdKeywords' => [...$keywords, $translated], 'freeKeywords' => ['heat storage', 'heat storage'],
    ], auth()->id());
    expect($updated)->toBeTrue()->and($saved->subjects)->toHaveCount(3)
        ->and($saved->subjects->where('subject_scheme', '!=', null)->pluck('language')->sort()->values()->all())->toBe(['de', 'en'])
        ->and(app(EditorDataTransformer::class)->transformGcmdKeywords($saved))->toHaveCount(2);
})->with(['aquifers', 'instruments']);

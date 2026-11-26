<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Http\Controllers\CitationLabelReviewController;
use App\Models\IdentifierType;
use App\Models\LandingPage;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceListingProjection;
use App\Models\User;
use App\Services\Citations\CitationLabelReviewService;
use App\Services\DataCiteApiService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

covers(CitationLabelReviewController::class, CitationLabelReviewService::class);

beforeEach(function () {
    $this->seed([IdentifierTypeSeeder::class, RelationTypeSeeder::class, TitleTypeSeeder::class]);
    $this->metadata = json_decode(file_get_contents(base_path('tests/fixtures/citations/ordinary-water.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->doi = $this->metadata['DOI'];
    $this->old = app(DataCiteApiService::class)->buildCitationFromMetadata($this->metadata);
    $this->resource = Resource::factory()->create(['updated_at' => now()->subDays(2)]);
    $this->page = LandingPage::factory()->published()->create(['resource_id' => $this->resource->id]);
    $this->user = User::factory()->create();
    $this->row = fn (?string $label, string $type = 'DOI', ?string $identifier = null, ?Resource $resource = null) => ($resource ?? $this->resource)->relatedIdentifiers()->create([
        'identifier' => $identifier ?? $this->doi, 'identifier_type_id' => IdentifierType::where('slug', $type)->firstOrFail()->id,
        'relation_type_id' => RelationType::where('slug', 'References')->firstOrFail()->id, 'citation_label' => $label, 'position' => 0,
    ]);
    $this->preview = fn (array $extra = []) => $this->postJson('/resources/citation-labels/preview', $extra + ['ids' => [$this->resource->id]]);
    $this->applyLabels = fn (array $tokens) => $this->postJson('/resources/citation-labels/apply', ['tokens' => $tokens]);
    Http::preventStrayRequests();
});

it('compares exact old labels, groups DOI variants and applies only the selected published labels', function () {
    $this->actingAs($this->user);
    Http::fake(['doi.org/*' => Http::response($this->metadata)]);
    $a = ($this->row)($this->old);
    $b = ($this->row)($this->old, 'URL', 'https://doi.org/'.strtoupper($this->doi));
    foreach ([strtolower($this->old), $this->old.' '] as $text) {
        ($this->row)($text);
    }
    ($this->row)(null);
    ($this->row)('Ordinary URL', 'URL', 'https://example.org/record');
    $draft = Resource::factory()->create();
    ($this->row)($this->old, resource: $draft);
    $response = ($this->preview)(['ids' => [$this->resource->id, $draft->id]])->assertOk()
        ->assertJsonCount(2, 'candidates')->assertJsonPath('skipped.changed_text', 2)->assertJsonPath('next_cursor', null);
    [$one, $two] = $response->json('candidates');
    expect($one['old'])->toBe($a->fresh()->citation_label)->and($one['work_type'])->toBe('Journal article')
        ->and($one['doi'])->toBe($two['doi'])->and($one['text'])->toContain('Science, 169(3946), 635–641.');
    Http::assertSentCount(1);
    $cache = Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags());
    $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($this->page->id);
    $cache->put($cacheKey, ['old' => true], 600);
    $originalDate = $this->resource->fresh()->updated_at;
    $this->travel(2)->seconds();
    ($this->applyLabels)([$two['token']])->assertOk()->assertJsonPath('results.0.status', 'applied');
    expect($a->fresh()->citation_label)->toBe($this->old)
        ->and($b->fresh()->citation_label)->toBe($two['text'])->and($b->fresh()->citation_label_parts)->toEqual($two['parts'])
        ->and($this->resource->fresh()->updated_at->gt($originalDate))->toBeTrue()->and($cache->has($cacheKey))->toBeFalse();
    ($this->applyLabels)([$two['token']])->assertOk()->assertJsonPath('results.0.status', 'already_applied');
    $a->update(['citation_label' => 'Curated after preview']);
    ($this->applyLabels)([$one['token']])->assertOk()->assertJsonPath('results.0.status', 'conflict');
    expect($a->fresh()->citation_label)->toBe('Curated after preview');
    ($this->preview)()->assertOk()->assertJsonCount(0, 'candidates');
    Http::assertSentCount(1);
});

it('refreshes updated sorting and the cached listing only for successful citation writes', function (string $operation): void {
    $this->actingAs($this->user);
    Http::fake(['doi.org/*' => Http::response($this->metadata)]);
    $other = Resource::factory()->create();
    LandingPage::factory()->published()->create(['resource_id' => $other->id]);
    $ids = [$this->resource->id, $other->id];
    foreach ([$this->resource, $other] as $resource) {
        ($this->row)($operation === 'hydrate' ? null : $this->old, resource: $resource);
    }
    $unchanged = Resource::factory()->create();
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    $sortDate = fn (int $id): string => ResourceListingProjection::findOrFail($id)->updated_sort;
    $unchangedDate = $sortDate($unchanged->id);
    $cache = CacheKey::RESOURCE_LIST->repository();
    $key = CacheKey::RESOURCE_LIST->key('updated-sort');
    $cache->put($key, $ids, 600);
    $this->travel(1)->hour();

    if ($operation === 'hydrate') {
        $save = fn () => $this->artisan('related-identifiers:hydrate-citation-labels')->assertSuccessful()->run();
    } else {
        $tokens = array_column(($this->preview)(['ids' => $ids])->assertOk()->json('candidates'), 'token');
        expect($tokens)->toHaveCount(2);
        $save = fn () => ($this->applyLabels)($tokens)->assertOk();
    }
    $save();

    foreach ($ids as $id) {
        expect($sortDate($id))->toBe(Resource::findOrFail($id)->updated_at->toIso8601String())
            ->and($sortDate($id))->toBeGreaterThan($unchangedDate);
    }
    expect($sortDate($unchanged->id))->toBe($unchangedDate)->and($cache->has($key))->toBeFalse();

    $cache->put($key, $ids, 600);
    $this->travel(1)->minute();
    $save();
    expect($cache->get($key))->toBe($ids);
})->with(['hydrate', 'apply']);

it('counts missing metadata, unrelated works and unknown sources separately', function () {
    $this->actingAs($this->user);
    foreach (['dataset', 'nosource', 'missing'] as $suffix) {
        ($this->row)('old', identifier: '10.1234/'.$suffix);
    }
    Http::fake([
        '*dataset' => Http::response(array_replace($this->metadata, ['type' => 'dataset'])),
        '*nosource' => Http::response(array_replace($this->metadata, ['container-title' => null])),
        '*missing' => Http::response([], 404),
    ]);
    ($this->preview)()->assertOk()->assertJsonCount(0, 'candidates')->assertJsonPath('skipped', [
        'other_type' => 1, 'changed_text' => 0, 'insufficient_metadata' => 1, 'lookup_failed' => 1,
    ]);
});

it('rechecks each resource, author and row before applying the exact signed proposal', function (string $change) {
    $this->actingAs($this->user);
    Http::fake(['doi.org/*' => Http::response($this->metadata)]);
    $row = ($this->row)($this->old);
    $token = ($this->preview)()->assertOk()->json('candidates.0.token');
    match ($change) {
        'unpublished' => $this->page->update(['is_published' => false]),
        'tombstone' => $this->page->forceFill(['is_tombstone' => true])->save(),
        'retargeted' => $row->update(['identifier' => '10.1234/elsewhere']),
        'deleted' => $row->delete(),
        'other reviewer' => $this->actingAs(User::factory()->create()),
        'tampered' => $token = substr($token, 4),
        'access revoked' => Gate::before(fn () => false),
    };
    $response = ($this->applyLabels)([$token]);
    if (in_array($change, ['other reviewer', 'tampered'], true)) {
        $response->assertUnprocessable()->assertJsonValidationErrors('tokens');
    } elseif ($change === 'access revoked') {
        $response->assertForbidden();
    } else {
        $response->assertOk()->assertJsonPath('results.0.status', 'conflict');
    }
    expect($row->fresh()?->citation_label_parts)->toBeNull();
})->with(['unpublished', 'tombstone', 'retargeted', 'deleted', 'other reviewer', 'tampered', 'access revoked']);

it('requires authenticated, authorized selections and paginates repeated works', function () {
    ($this->preview)()->assertUnauthorized();
    ($this->applyLabels)(['invalid'])->assertUnauthorized();
    $this->actingAs($this->user);
    ($this->preview)(['ids' => []])->assertUnprocessable();
    ($this->applyLabels)([])->assertUnprocessable();
    Http::fake(['doi.org/*' => Http::response($this->metadata)]);
    foreach (range(1, 17) as $i) {
        ($this->row)($this->old);
    }
    $reviewId = (string) Str::uuid();
    $first = ($this->preview)(['review_id' => $reviewId])->assertOk()->assertJsonCount(16, 'candidates');
    ($this->preview)(['cursor' => $first->json('next_cursor'), 'review_id' => $reviewId])->assertOk()->assertJsonCount(1, 'candidates')->assertJsonPath('next_cursor', null);
    Http::assertSentCount(1);
    Gate::before(fn () => false);
    ($this->preview)()->assertForbidden();
});

it('isolates transient lookup failures per reviewer and review for thirty minutes', function () {
    $this->actingAs($this->user);
    Http::fakeSequence()->push([], 503)->push($this->metadata);
    foreach (range(1, 17) as $i) {
        ($this->row)($this->old);
    }
    $run = ['review_id' => (string) Str::uuid()];
    $first = ($this->preview)($run)->assertOk()->assertJsonPath('skipped.lookup_failed', 16);
    $this->travel(29)->minutes();
    ($this->preview)($run + ['cursor' => $first->json('next_cursor')])->assertOk()->assertJsonPath('skipped.lookup_failed', 1);
    Http::assertSentCount(1);
    $this->actingAs(User::factory()->create());
    ($this->preview)($run)->assertOk()->assertJsonCount(16, 'candidates');
    $this->actingAs($this->user);
    ($this->preview)(['review_id' => (string) Str::uuid()])->assertOk()->assertJsonCount(16, 'candidates');
    ($this->preview)($run)->assertOk()->assertJsonPath('skipped.lookup_failed', 16);
    $this->travel(2)->minutes();
    ($this->preview)($run)->assertOk()->assertJsonCount(16, 'candidates');
    Http::assertSentCount(2);
});

it('limits preview pages per user before DOI lookups and lets reviewed proposals be applied', function (): void {
    $this->freezeTime();
    $this->actingAs($this->user);
    Http::fake(['doi.org/*' => Http::response($this->metadata)]);
    $row = ($this->row)($this->old);
    $first = ($this->preview)()->assertOk()->assertHeader('X-RateLimit-Limit', '6');
    $token = $first->json('candidates.0.token');

    $nextPage = ['cursor' => $row->id, 'review_id' => (string) Str::uuid()];
    foreach (range(1, 5) as $_) {
        ($this->preview)($nextPage)->assertOk();
    }
    ($this->row)('Old label', identifier: '10.1234/next-paper');
    ($this->preview)(['review_id' => (string) Str::uuid()] + $nextPage)
        ->assertTooManyRequests()->assertHeader('Retry-After', '60');
    Http::assertSentCount(1);
    ($this->applyLabels)([$token])->assertOk()->assertJsonPath('results.0.status', 'applied');

    $this->actingAs(User::factory()->create());
    ($this->preview)($nextPage)->assertOk()->assertHeader('X-RateLimit-Remaining', '5');
    Http::assertSentCount(2);
    $this->actingAs($this->user);
    $this->travel(61)->seconds();
    ($this->preview)($nextPage)->assertOk()->assertHeader('X-RateLimit-Remaining', '5');
});

it('backfills missing labels and typography together for repeated DOI forms', function () {
    Http::fake(['doi.org/*' => Http::response($this->metadata)]);
    $missing = ($this->row)(null);
    $url = ($this->row)(null, 'URL', 'https://doi.org/'.strtoupper($this->doi));
    $curated = ($this->row)('Curated');
    $this->artisan('related-identifiers:hydrate-citation-labels')->assertSuccessful();
    expect($missing->fresh()->citation_label)->toContain('Science, 169(3946), 635–641.')
        ->and($url->fresh()->citation_label_parts)->toEqual($missing->fresh()->citation_label_parts)
        ->and(implode('', array_column($url->fresh()->citation_label_parts, 'text')))->toBe($url->fresh()->citation_label)
        ->and($curated->fresh()->citation_label_parts)->toBeNull();
    Http::assertSentCount(1);
});

it('persists the APA label through a full metadata upload', function (string $format) {
    $this->actingAs($this->user);
    Http::fake(['doi.org/*' => Http::response($this->metadata)]);
    $content = $format === 'xml'
        ? '<resource xmlns="http://datacite.org/schema/kernel-4"><titles><title>Upload</title></titles><publicationYear>2026</publicationYear><relatedIdentifiers><relatedIdentifier relatedIdentifierType="DOI" relationType="Cites">'.$this->doi.'</relatedIdentifier></relatedIdentifiers></resource>'
        : dataCiteJson(minimalAttributes([
            'relatedIdentifiers' => [['relatedIdentifierType' => 'URL', 'relationType' => 'Cites', 'relatedIdentifier' => 'https://doi.org/'.$this->doi]],
        ]));
    $file = UploadedFile::fake()->createWithContent('paper.'.$format, $content);
    $id = $this->postJson('/dashboard/upload-'.$format, ['file' => $file])->assertOk()->json('resourceId');
    $row = Resource::findOrFail($id)->relatedIdentifiers()->sole();
    expect($row->citation_label)->toContain('Science, 169(3946), 635–641.')
        ->and(implode('', array_column($row->citation_label_parts, 'text')))->toBe($row->citation_label);
    Http::assertSentCount(1);
})->with(['xml', 'json']);

it('round trips signed editor labels and handles stale or manually edited text', function () {
    $this->actingAs($this->user);
    Http::fake(['doi.org/*' => Http::response($this->metadata)]);
    $lookup = $this->getJson('/api/v1/related-identifiers/citation-label?'.http_build_query(['identifier' => $this->doi, 'identifierType' => 'DOI']))
        ->assertOk()->json();
    $entry = ['identifier' => $this->doi, 'identifierType' => 'DOI', 'relationType' => 'Cites', 'citationLabel' => $lookup['citation'], 'citationProof' => $lookup['citation_proof']];
    $draft = ['intent' => 'save-draft', 'titles' => [['title' => 'APA draft', 'titleType' => 'MainTitle']]];
    $url = '/editor/resources/draft';
    $saved = $this->postJson($url, [...$draft, 'relatedIdentifiers' => [$entry]])->assertCreated();
    $draft['resourceId'] = $saved->json('resource.id');
    $row = RelatedIdentifier::findOrFail($saved->json('relatedWorkLabels.0.id'));
    expect(implode('', array_column($row->citation_label_parts, 'text')))->toBe($lookup['citation']);
    $entry += ['id' => $row->id, 'originalCitationLabelHash' => $saved->json('relatedWorkLabels.0.citation_label_hash')];
    $row->update(['citation_label' => 'Concurrent correction', 'citation_label_parts' => null]);
    $this->postJson($url, [...$draft, 'relatedIdentifiers' => [$entry]])->assertOk()->assertJsonPath('relatedWorkLabels.0.citation_label', 'Concurrent correction');
    expect($row->fresh()->citation_label_parts)->toBeNull();
    $entry['citationLabel'] = 'Manual edit';
    $this->postJson($url, [...$draft, 'relatedIdentifiers' => [$entry]])->assertUnprocessable()->assertJsonValidationErrors('relatedIdentifiers.0.citationLabel');
    $entry['originalCitationLabelHash'] = hash('sha256', 'Concurrent correction');
    $this->postJson($url, [...$draft, 'relatedIdentifiers' => [$entry]])->assertOk();
    expect($row->fresh()->citation_label)->toBe('Manual edit')->and($row->fresh()->citation_label_parts)->toBeNull();
    Http::assertSentCount(1);
});

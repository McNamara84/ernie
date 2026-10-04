<?php

declare(strict_types=1);

use App\Services\DataCiteEventDataService;
use App\Services\RelationTypeCorrection\RelationMetadataClientService;
use App\Services\RelationTypeCorrection\RelationSupplementaryClientService;
use App\Services\RelationTypeCorrection\RelationTypeRules;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

it('retains DataCite directed assertions and source pointers while ignoring malformed claims', function (): void {
    $rows = [
        ['relatedIdentifierType' => 'DOI', 'relatedIdentifier' => 'HTTPS://DOI.ORG/10.5880/B', 'relationType' => 'HasPart'],
        ['relatedIdentifierType' => 'URL', 'relatedIdentifier' => 'https://example.org', 'relationType' => 'HasPart'],
        ['relatedIdentifierType' => 'DOI', 'relatedIdentifier' => '10.5880/a', 'relationType' => 'HasPart'],
        ['relatedIdentifierType' => 'DOI', 'relatedIdentifier' => '10.5880/b', 'relationType' => 'unknown'], null,
    ];
    $claims = app(RelationMetadataClientService::class)->parse('datacite', '10.5880/a', ['relatedIdentifiers' => $rows], 'now');
    expect($claims)->toHaveCount(1)->and($claims[0]->fromPerspective('10.5880/b'))->toBe('IsPartOf')
        ->and($claims[0]->toArray()['source_pointer'])->toBe('/data/attributes/relatedIdentifiers/0')
        ->and($claims[0]->primary)->toBeTrue();
});

it('maps only exact Crossref semantics', function (string $name, ?string $slug): void {
    expect(RelationTypeRules::crossref($name))->toBe($slug);
})->with([
    ['has-derivation', 'IsSourceOf'], ['is-derived-from', 'IsDerivedFrom'], ['has-review', 'IsReviewedBy'], ['is-review-of', 'Reviews'],
    ['is-version-of', 'IsVersionOf'], ['has-version', 'HasVersion'], ['has-part', 'HasPart'], ['is-part-of', 'IsPartOf'],
    ['is-identical-to', 'IsIdenticalTo'], ['has-translation', 'HasTranslation'], ['is-translation-of', 'IsTranslationOf'],
    ['is-supplement-to', 'IsSupplementTo'], ['is-supplemented-by', 'IsSupplementedBy'], ['documents', 'Documents'], ['is-documented-by', 'IsDocumentedBy'],
    ['is-based-on', null], ['is-preprint-of', null], ['has-expression', null], ['is-manifestation-of', null], ['unknown', null],
]);

it('preserves asserted-by object provenance without reversing the Crossref relation twice', function (): void {
    $claims = app(RelationMetadataClientService::class)->parse('crossref', '10.5880/a', ['relation' => ['has-part' => [
        ['id' => '10.5880/b', 'id-type' => 'doi', 'asserted-by' => 'object'],
        ['id' => '10.5880/c', 'id-type' => 'doi'],
        ['id' => '10.5880/d', 'id-type' => 'uri', 'asserted-by' => 'subject'],
    ]], 'reference' => [['DOI' => '10.5880/b'], ['unstructured' => 'A title']]], 'now');
    expect($claims)->toHaveCount(2)->and($claims[0]->claimant)->toBe('10.5880/b')
        ->and($claims[0]->originKey)->toBe('crossref:10.5880/b')
        ->and($claims[0]->fromPerspective('10.5880/b'))->toBe('IsPartOf')
        ->and($claims[1]->primary)->toBeFalse();
});

it('distinguishes unavailable, missing and incomplete records and retries transient failures', function (): void {
    Http::fakeSequence()->push([], 503)->push([], 503)->push([], 404)->push(['data' => ['attributes' => ['doi' => '10.5880/wrong']]])->push(['data' => ['attributes' => ['doi' => '10.5880/d']]]);
    $client = app(RelationMetadataClientService::class);
    expect($client->record('datacite', '10.5880/a')['status'])->toBe('unavailable')
        ->and($client->record('datacite', '10.5880/b')['status'])->toBe('not_found')
        ->and($client->record('datacite', '10.5880/c')['status'])->toBe('incomplete')
        ->and($client->record('datacite', '10.5880/d')['status'])->toBe('ok');
    $client->record('datacite', '10.5880/d');
    Http::assertSentCount(5);
    $this->travel(301)->seconds();
    Http::swap(new Factory);
    Http::fake(['*' => Http::response([], 404)]);
    expect($client->record('datacite', '10.5880/a')['status'])->toBe('not_found');
});

it('uses short error caches and refreshes successful records after 24 hours', function (): void {
    Http::fake(['*' => Http::response(['message' => ['DOI' => '10.5880/a']])]);
    $client = app(RelationMetadataClientService::class);
    $client->record('crossref', '10.5880/a');
    $this->travel(23)->hours();
    $client->record('crossref', '10.5880/a');
    Http::assertSentCount(1);
    $this->travel(2)->hours();
    $client->record('crossref', '10.5880/a');
    Http::assertSentCount(2);
});

it('keeps supplementary assertion direction and excludes unsupported inverses', function (): void {
    $client = app(RelationSupplementaryClientService::class);
    $claim = $client->parse('datacite_event_data', ['attributes' => ['subj-id' => 'https://doi.org/10.5880/b', 'obj-id' => '10.5880/a', 'relation-type-id' => 'has-part', 'source-id' => 'datacite-related']], 'https://api.datacite.org/events', '/data/0', 'now');
    expect($claim->primary)->toBeFalse()->and($claim->fromPerspective('10.5880/a'))->toBe('IsPartOf');
    $scholix = $client->parse('scholexplorer', ['source' => ['Identifier' => [['ID' => '10.5880/a']]], 'target' => ['Identifier' => [['ID' => '10.5880/b']]], 'RelationshipType' => ['Name' => 'IsDerivedFrom']], 'https://example.org', '/result/0', 'now');
    expect($scholix->relation)->toBe('IsDerivedFrom')
        ->and($client->parse('datacite_event_data', [], '', '', ''))->toBeNull();
});

it('bounds supplementary pagination and records truncation without creating primary evidence', function (): void {
    Http::fake(['*events*' => Http::response(['data' => [], 'links' => ['next' => 'https://untrusted.example/next']]), '*Links*' => Http::response(['result' => [], 'totalPages' => 50])]);
    $result = app(RelationSupplementaryClientService::class)->forPair('10.5880/a', '10.5880/b');
    expect($result['evidence'])->toBe([])->and(array_column($result['sources'], 'status'))->toBe(['incomplete', 'incomplete']);
    Http::assertSentCount(6);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'untrusted.example'));
});

it('inverts the existing Event Data adapter when the queried DOI is the object', function (): void {
    Http::fake(['*' => Http::response(['data' => [
        ['attributes' => ['subj-id' => 'https://doi.org/10.5880/b', 'obj-id' => 'https://doi.org/10.5880/a', 'relation-type-id' => 'has-part']],
        ['attributes' => ['subj-id' => '10.5880/b', 'obj-id' => '10.5880/a', 'relation-type-id' => 'is-published-in']],
        ['attributes' => ['subj-id' => '10.5880/a', 'obj-id' => '10.5880/c', 'relation-type-id' => 'cites']],
    ]])]);
    $relations = app(DataCiteEventDataService::class)->findRelationsForDoi('HTTPS://DOI.ORG/10.5880/A');
    expect(array_column($relations, 'relation_type'))->toBe(['IsPartOf', 'Cites']);
    Http::assertSent(fn ($request): bool => str_contains($request['source-id'], 'datacite-related'));
});

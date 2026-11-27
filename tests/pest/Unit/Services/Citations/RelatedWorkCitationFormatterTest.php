<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Services\Citations\RelatedWorkCitation;
use App\Services\Citations\RelatedWorkCitationFormatter;
use Illuminate\Support\Facades\Http;

covers(RelatedWorkCitationFormatter::class, RelatedWorkCitation::class);

function examplePaperMetadata(array $overrides = []): array
{
    $metadata = json_decode(file_get_contents(base_path('tests/fixtures/citations/ordinary-water.json')), true, flags: JSON_THROW_ON_ERROR);

    return array_replace($metadata, $overrides);
}

it('renders the journal and volume in italics with exactly matching APA clipboard text', function () {
    $doi = '10.1126/science.169.3946.635';
    $citation = app(RelatedWorkCitationFormatter::class)->format($doi, examplePaperMetadata());
    expect($citation->text)->toBe('Frank, H. S. (1970). The structure of ordinary water. Science, 169(3946), 635–641. https://doi.org/'.$doi)
        ->and(implode('', array_column(array_filter($citation->parts, fn ($p) => $p['italic']), 'text')))->toBe('Science169')
        ->and($citation->hasSource)->toBeTrue();
    $proof = $citation->proof();
    expect(RelatedWorkCitation::verifiedParts($proof, $doi, $citation->text))->toBe($citation->parts)
        ->and(RelatedWorkCitation::verifiedParts($proof, $doi, 'Edited'))->toBeNull()
        ->and(RelatedWorkCitation::verifiedParts($proof, '10.1234/different', $citation->text))->toBeNull()
        ->and(RelatedWorkCitation::verifiedParts('invalid', $doi, $citation->text))->toBeNull();
});

it('keeps the legacy text for other and ambiguous records', function (string $type) {
    Http::fake(['*' => Http::response([], 404)]);
    $metadata = examplePaperMetadata(['type' => $type]);
    $citation = app(RelatedWorkCitationFormatter::class)->format($metadata['DOI'], $metadata);
    expect($citation->text)->toBe('Frank, H. S. (1970): The structure of ordinary water. American Association for the Advancement of Science. https://doi.org/10.1126/science.169.3946.635')
        ->and($citation->parts)->toBeNull();
})->with(['dataset', 'software', 'book', 'proceedings', 'article']);

it('reuses saved APA parts for the exact CSL item until the entry is forgotten', function (): void {
    $doi = '10.1234/cached-paper';
    $metadata = ['title' => 'First title', 'type' => 'article-journal'];
    $item = ['id' => $doi, 'DOI' => $doi, 'URL' => 'https://doi.org/'.$doi, ...$metadata];
    $key = CacheKey::CITATION_APA_PARTS;
    $suffix = hash('sha256', serialize($item));
    $parts = [['text' => 'Previously rendered citation', 'italic' => true]];
    $key->repository()->put($key->key($suffix), $parts, $key->ttl());

    $formatter = app(RelatedWorkCitationFormatter::class);
    expect($formatter->format($doi, $metadata)->parts)->toBe($parts)
        ->and($formatter->format($doi, [...$metadata, 'title' => 'Revised title'])->text)->toContain('Revised title')
        ->and($key->forget($suffix))->toBeTrue()
        ->and($formatter->format($doi, $metadata)->text)->toContain('First title');
});

it('renders a conference chapter with its proceedings and a preprint with its archive', function () {
    $formatter = app(RelatedWorkCitationFormatter::class);
    $chapter = $formatter->format('10.1234/chapter', examplePaperMetadata([
        'type' => 'paper-conference', 'container-title' => ['Earth science proceedings'], 'ISBN' => ['1234'],
        'editor' => [['literal' => 'Research Council']], 'publisher' => 'Example Press',
    ]));
    expect($chapter->text)->toContain('Research Council', 'Earth science proceedings', 'Example Press')
        ->and($chapter->workType)->toBe('Conference paper');
    $preprint = $formatter->format('10.1234/preprint', examplePaperMetadata([
        'type' => 'article', 'subtype' => 'preprint', 'archive' => 'PsyArXiv',
    ]));
    expect($preprint->text)->toContain('PsyArXiv', 'https://doi.org/10.1234/preprint')->not->toContain('Science')
        ->and($preprint->workType)->toBe('Preprint');
    $unknownArchive = $formatter->format('10.1234/preprint', examplePaperMetadata(['type' => 'preprint']));
    expect($unknownArchive->text)->not->toContain('Science', 'American Association')->and($unknownArchive->hasSource)->toBeFalse();
});

it('omits an absent journal, preserves literal author names and prints an article number', function () {
    $citation = app(RelatedWorkCitationFormatter::class)->format('10.1234/number', examplePaperMetadata([
        'author' => [['literal' => 'R&D Council']], 'container-title' => null, 'page' => null, 'issue' => null,
        'article-number' => 'e123', 'title' => 'Comparing A < B & C',
    ]));
    expect($citation->text)->toContain('R&D Council', 'A < B & C', 'Article e123')->not->toContain('American Association')
        ->and($citation->hasSource)->toBeFalse();
});

it('uses the native preprint subtype for ambiguous CSL and caches the exact DOI answer', function () {
    Http::fake(['api.crossref.org/*' => Http::response(['message' => [
        'DOI' => '10.1234/p', 'type' => 'posted-content', 'subtype' => 'preprint', 'institution' => [['name' => 'EarthArXiv']],
    ]])]);
    $formatter = app(RelatedWorkCitationFormatter::class);
    $metadata = examplePaperMetadata(['type' => 'article', 'container-title' => null]);
    expect($formatter->format('10.1234/p', $metadata)->text)->toContain('EarthArXiv');
    expect($formatter->format('10.1234/p', $metadata)->workType)->toBe('Preprint');
    Http::assertSentCount(1);
    expect(CacheKey::CITATION_NATIVE_PAPER->forget(hash('sha256', '10.1234/p')))->toBeTrue()
        ->and($formatter->format('10.1234/p', $metadata)->workType)->toBe('Preprint');
    Http::assertSentCount(2);
});

it('formats serial conference contributions and presentations according to their source', function () {
    $formatter = app(RelatedWorkCitationFormatter::class);
    $serial = $formatter->format('10.1234/conf', examplePaperMetadata(['type' => 'paper-conference', 'ISSN' => ['1234-5678']]));
    expect($serial->text)->toContain('Science, 169(3946), 635–641.')->and($serial->workType)->toBe('Conference paper');
    $talk = $formatter->format('10.1234/talk', examplePaperMetadata(['type' => 'paper-conference', 'container-title' => null,
        'event' => ['name' => 'Earth Science Meeting', 'location' => 'Berlin'], 'page' => null, 'issue' => null, 'volume' => null]));
    expect($talk->text)->toContain('Earth Science Meeting', 'Berlin')->and($talk->hasSource)->toBeTrue();
    expect($formatter->format('10.1234/incomplete', examplePaperMetadata(['title' => null])))->toBeNull();
});

it('confirms ambiguous CSL types against the matching native DataCite DOI', function () {
    Http::fake([
        'api.crossref.org/*' => Http::response([], 404),
        'api.datacite.org/*' => Http::response(['data' => ['attributes' => ['doi' => '10.1234/dc-preprint',
            'types' => ['resourceTypeGeneral' => 'Preprint', 'resourceType' => 'Preprint']]]]),
    ]);
    $metadata = examplePaperMetadata(['type' => 'manuscript', 'repository' => 'EarthArXiv']);
    $formatter = app(RelatedWorkCitationFormatter::class);
    expect($formatter->format('10.1234/dc-preprint', $metadata)->text)->toContain('EarthArXiv')
        ->and($formatter->format('10.1234/mismatched', $metadata)->parts)->toBeNull();
});

it('respects the remaining lookup budget and excludes conflicting or nonpreprint types', function () {
    Http::preventStrayRequests();
    $formatter = app(RelatedWorkCitationFormatter::class);
    foreach ([
        ['type' => 'posted-content', 'subtype' => 'working_paper'],
        ['type' => 'article-journal', 'types' => ['resourceTypeGeneral' => 'Dataset']],
        ['type' => 'manuscript'],
    ] as $fields) {
        $result = $formatter->format('10.1234/ambiguous', examplePaperMetadata($fields), microtime(true) - 1);
        expect($result->parts)->toBeNull();
    }
    Http::assertNothingSent();
});

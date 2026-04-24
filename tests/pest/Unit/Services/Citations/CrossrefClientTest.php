<?php

declare(strict_types=1);

use App\Services\Citations\CitationLookupResult;
use App\Services\Citations\CrossrefClient;
use App\Services\Citations\CrossrefTypeMapper;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

covers(CrossrefClient::class, CitationLookupResult::class);

beforeEach(function () {
    config()->set('crossref.base_url', 'https://api.crossref.org/works/');
    config()->set('crossref.mailto', 'test@example.org');
    config()->set('crossref.timeout', 5);
});

function makeClient(): CrossrefClient
{
    return new CrossrefClient(app(HttpFactory::class), new CrossrefTypeMapper());
}

it('returns an error for empty DOIs', function () {
    $result = makeClient()->lookup('   ');
    expect($result->found)->toBeFalse();
    expect($result->error)->not->toBeNull();
});

it('transforms a successful Crossref response into the canonical schema', function () {
    Http::fake([
        'api.crossref.org/*' => Http::response([
            'message' => [
                'type' => 'journal-article',
                'title' => ['Great Paper'],
                'subtitle' => ['A really great subtitle'],
                'author' => [
                    ['given' => 'Anna', 'family' => 'Müller', 'ORCID' => 'https://orcid.org/0000-0002-1825-0097'],
                    ['given' => 'Ben', 'family' => 'Schmidt'],
                ],
                'publisher' => 'ACME',
                'container-title' => ['Journal of Science'],
                'volume' => '12',
                'issue' => '3',
                'page' => '101-115',
                'issued' => ['date-parts' => [[2023, 5, 10]]],
                'ISSN' => ['1234-5678'],
            ],
        ], 200),
    ]);

    $result = makeClient()->lookup('10.1234/abcd');

    expect($result->found)->toBeTrue();
    expect($result->source)->toBe('crossref');
    expect($result->data['relatedItemType'])->toBe('JournalArticle');
    expect($result->data['titles'])->toHaveCount(2);
    expect($result->data['titles'][0])->toMatchArray(['title' => 'Great Paper', 'titleType' => 'MainTitle']);
    expect($result->data['titles'][1]['titleType'])->toBe('Subtitle');
    expect($result->data['creators'])->toHaveCount(2);
    expect($result->data['creators'][0]['nameIdentifier'])->toBe('0000-0002-1825-0097');
    expect($result->data['creators'][0]['nameIdentifierScheme'])->toBe('ORCID');
    expect($result->data['publicationYear'])->toBe(2023);
    expect($result->data['volume'])->toBe('12');
    expect($result->data['issue'])->toBe('3');
    expect($result->data['firstPage'])->toBe('101');
    expect($result->data['lastPage'])->toBe('115');
    expect($result->data['publisher'])->toBe('Journal of Science'); // container-title preferred
    expect($result->data['identifier'])->toBe('10.1234/abcd');
    expect($result->data['identifierType'])->toBe('DOI');
    expect($result->data['additionalIdentifiers'])->toContain(['identifier' => '1234-5678', 'identifierType' => 'ISSN']);
});

it('returns notFound for a 404 response', function () {
    Http::fake([
        'api.crossref.org/*' => Http::response([], 404),
    ]);

    $result = makeClient()->lookup('10.9999/notfound');
    expect($result->found)->toBeFalse();
    expect($result->error)->toBeNull();
    expect($result->source)->toBe('crossref');
});

it('returns an error for other HTTP failures', function () {
    Http::fake([
        'api.crossref.org/*' => Http::response('oops', 500),
    ]);

    $result = makeClient()->lookup('10.1/500');
    expect($result->found)->toBeFalse();
    expect($result->error)->toBeString()->toMatch('/500/');
});

it('handles an organizational author (name field)', function () {
    Http::fake([
        'api.crossref.org/*' => Http::response([
            'message' => [
                'type' => 'report',
                'title' => ['GFZ Annual Report'],
                'author' => [['name' => 'GFZ Helmholtz Centre']],
            ],
        ], 200),
    ]);

    $result = makeClient()->lookup('10.1/report');

    expect($result->found)->toBeTrue();
    expect($result->data['relatedItemType'])->toBe('Report');
    expect($result->data['creators'][0]['nameType'])->toBe('Organizational');
    expect($result->data['creators'][0]['name'])->toBe('GFZ Helmholtz Centre');
});

it('sends the polite pool user agent when mailto is configured', function () {
    Http::fake([
        'api.crossref.org/*' => Http::response(['message' => []], 200),
    ]);

    makeClient()->lookup('10.1/polite');

    Http::assertSent(function ($request) {
        /** @var \Illuminate\Http\Client\Request $request */
        return str_contains((string) $request->header('User-Agent')[0], 'mailto:test@example.org');
    });
});

it('converts a DOI URL to a bare DOI before calling Crossref', function () {
    Http::fake([
        'api.crossref.org/*' => Http::response(['message' => []], 200),
    ]);

    makeClient()->lookup('https://doi.org/10.1234/ABCD');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '10.1234');
    });
});

describe('CitationLookupResult', function () {
    it('serializes toArray correctly', function () {
        $r = CitationLookupResult::hit('crossref', ['foo' => 'bar']);
        expect($r->toArray())->toMatchArray([
            'source' => 'crossref',
            'found' => true,
            'data' => ['foo' => 'bar'],
            'error' => null,
        ]);

        $r2 = CitationLookupResult::notFound('datacite');
        expect($r2->toArray())->toMatchArray(['source' => 'datacite', 'found' => false, 'data' => null]);

        $r3 = CitationLookupResult::error('crossref', 'boom');
        expect($r3->error)->toBe('boom');
        expect($r3->found)->toBeFalse();
    });
});

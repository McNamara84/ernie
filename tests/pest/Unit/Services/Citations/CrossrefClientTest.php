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

describe('CrossrefClient edge cases', function () {
    it('returns an error when the HTTP client throws', function () {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });

        $result = makeClient()->lookup('10.1/timeout');

        expect($result->found)->toBeFalse();
        expect($result->error)->toBe('Crossref request failed.');
    });

    it('returns notFound when the response body is not a JSON array', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response('not-json-array', 200, ['Content-Type' => 'text/plain']),
        ]);

        $result = makeClient()->lookup('10.1/plain');

        expect($result->found)->toBeFalse();
        expect($result->error)->toBeNull();
    });

    it('returns notFound when message is missing', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response(['status' => 'ok'], 200),
        ]);

        expect(makeClient()->lookup('10.1/nomsg')->found)->toBeFalse();
    });

    it('returns notFound when message is not an array', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response(['message' => 'oops'], 200),
        ]);

        expect(makeClient()->lookup('10.1/strmsg')->found)->toBeFalse();
    });

    it('sends the bare user agent when mailto is empty', function () {
        config()->set('crossref.mailto', '');

        Http::fake([
            'api.crossref.org/*' => Http::response(['message' => []], 200),
        ]);

        makeClient()->lookup('10.1/noemail');

        Http::assertSent(function ($request) {
            $ua = (string) $request->header('User-Agent')[0];

            return $ua === 'ERNIE/1.0';
        });
    });

    it('skips authors that have neither name nor given/family', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response([
                'message' => [
                    'title' => ['T'],
                    'author' => [['sequence' => 'first'], ['given' => 'A', 'family' => 'B']],
                ],
            ], 200),
        ]);

        $result = makeClient()->lookup('10.1/skip');

        expect($result->data['creators'])->toHaveCount(1);
        expect($result->data['creators'][0]['familyName'])->toBe('B');
    });

    it('extracts authors that have only a given name', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response([
                'message' => [
                    'title' => ['T'],
                    'author' => [['given' => 'Cher']],
                ],
            ], 200),
        ]);

        $result = makeClient()->lookup('10.1/only-given');

        expect($result->data['creators'])->toHaveCount(1);
        expect($result->data['creators'][0]['nameType'])->toBe('Personal');
        expect($result->data['creators'][0]['givenName'])->toBe('Cher');
        expect($result->data['creators'][0]['familyName'])->toBeNull();
    });

    it('extracts affiliation names for an author', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response([
                'message' => [
                    'title' => ['T'],
                    'author' => [[
                        'given' => 'A',
                        'family' => 'B',
                        'affiliation' => [
                            ['name' => 'GFZ'],
                            ['something' => 'else'], // missing name, skipped
                            'not-an-array', // skipped
                        ],
                    ]],
                ],
            ], 200),
        ]);

        $result = makeClient()->lookup('10.1/aff');

        expect($result->data['creators'][0]['affiliations'])->toHaveCount(1);
        expect($result->data['creators'][0]['affiliations'][0]['name'])->toBe('GFZ');
    });

    it('falls back through date fields to extract the publication year', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response([
                'message' => [
                    'title' => ['T'],
                    'published-online' => ['date-parts' => [[2018]]],
                    'created' => ['date-parts' => [[2010]]],
                ],
            ], 200),
        ]);

        $result = makeClient()->lookup('10.1/online');

        expect($result->data['publicationYear'])->toBe(2018);
    });

    it('ignores non-positive years', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response([
                'message' => [
                    'title' => ['T'],
                    'issued' => ['date-parts' => [[0]]],
                ],
            ], 200),
        ]);

        $result = makeClient()->lookup('10.1/zero');

        expect($result->data['publicationYear'])->toBeNull();
    });

    it('parses a single page (no dash)', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response([
                'message' => [
                    'title' => ['T'],
                    'page' => '42',
                ],
            ], 200),
        ]);

        $result = makeClient()->lookup('10.1/single-page');

        expect($result->data['firstPage'])->toBe('42');
        expect($result->data['lastPage'])->toBeNull();
    });

    it('falls back to publisher when container-title is empty', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response([
                'message' => [
                    'title' => ['T'],
                    'publisher' => 'Imprint Co',
                    'container-title' => ['', '  '],
                ],
            ], 200),
        ]);

        $result = makeClient()->lookup('10.1/publisher');

        expect($result->data['publisher'])->toBe('Imprint Co');
    });

    it('extracts ISBN identifiers', function () {
        Http::fake([
            'api.crossref.org/*' => Http::response([
                'message' => [
                    'type' => 'book',
                    'title' => ['The Book'],
                    'ISBN' => ['978-0-12-345678-9'],
                ],
            ], 200),
        ]);

        $result = makeClient()->lookup('10.1/book');

        expect($result->data['additionalIdentifiers'])->toContain([
            'identifier' => '978-0-12-345678-9',
            'identifierType' => 'ISBN',
        ]);
    });
});

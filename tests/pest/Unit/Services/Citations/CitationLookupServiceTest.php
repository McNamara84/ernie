<?php

declare(strict_types=1);

use App\Services\Citations\CitationLookupResult;
use App\Services\Citations\CitationLookupService;
use App\Services\Citations\CrossrefClient;
use App\Services\Citations\DataCiteTypeMapper;
use App\Services\DataCiteApiService;
use Illuminate\Support\Facades\Cache;

covers(CitationLookupService::class);

beforeEach(function () {
    Cache::flush();
});

it('returns the crossref result when found and does not call DataCite', function () {
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')
        ->once()
        ->with('10.1/hit')
        ->andReturn(CitationLookupResult::hit('crossref', ['identifier' => '10.1/hit']));

    $datacite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $datacite->shouldNotReceive('getDataCiteMetadata');

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());
    $result = $svc->lookup('10.1/hit');

    expect($result->source)->toBe('crossref');
    expect($result->found)->toBeTrue();
});

it('falls back to DataCite when Crossref returns notFound', function () {
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')->once()->andReturn(CitationLookupResult::notFound('crossref'));

    $datacite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $datacite->shouldReceive('getDataCiteMetadata')->once()->andReturn([
        'titles' => [['title' => 'From DataCite']],
        'creators' => [[
            'nameType' => 'Personal',
            'name' => 'Doe, Jane',
            'givenName' => 'Jane',
            'familyName' => 'Doe',
            'nameIdentifiers' => [[
                'nameIdentifier' => '0000-0001-0002-0003',
                'nameIdentifierScheme' => 'ORCID',
            ]],
        ]],
        'types' => ['resourceTypeGeneral' => 'Dataset'],
        'publicationYear' => 2022,
        'publisher' => 'GFZ',
    ]);

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());
    $result = $svc->lookup('10.1/fallback');

    expect($result->source)->toBe('datacite');
    expect($result->found)->toBeTrue();
    expect($result->data['titles'][0]['title'])->toBe('From DataCite');
    expect($result->data['creators'][0]['nameIdentifier'])->toBe('0000-0001-0002-0003');
    expect($result->data['relatedItemType'])->toBe('Dataset');
    expect($result->data['publicationYear'])->toBe(2022);
});

it('returns notFound when neither source has the DOI', function () {
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')->andReturn(CitationLookupResult::notFound('crossref'));

    $datacite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $datacite->shouldReceive('getDataCiteMetadata')->andReturn(null);

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());
    $result = $svc->lookup('10.1/missing');

    expect($result->found)->toBeFalse();
    expect($result->source)->toBe('datacite');
});

it('caches the result so repeat calls do not hit the network', function () {
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')->once()->andReturn(CitationLookupResult::hit('crossref', ['x' => 1]));

    $datacite = Mockery::mock(DataCiteApiService::class)->makePartial();

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());

    $r1 = $svc->lookup('10.1/CACHED');
    $r2 = $svc->lookup('10.1/cached'); // case-insensitive cache key

    expect($r1->found)->toBeTrue();
    expect($r2->found)->toBeTrue();
});

it('falls back to DataCite when Crossref returns an error', function () {
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')->once()->andReturn(CitationLookupResult::error('crossref', 'HTTP 500'));

    $datacite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $datacite->shouldReceive('getDataCiteMetadata')->once()->andReturn([
        'titles' => [['title' => 'Recovered']],
        'publicationYear' => 2020,
    ]);

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());
    $result = $svc->lookup('10.1/err');

    expect($result->source)->toBe('datacite');
    expect($result->found)->toBeTrue();
    expect($result->data['titles'][0]['title'])->toBe('Recovered');
});

it('does not cache a DataCite notFound when the Crossref primary errored', function () {
    // Crossref outage → DataCite has no record either. We must NOT cache the
    // notFound, because Crossref might come back online with the DOI and
    // would otherwise be locked out for the full TTL.
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')
        ->twice()
        ->andReturn(CitationLookupResult::error('crossref', 'HTTP 500'));

    $datacite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $datacite->shouldReceive('getDataCiteMetadata')
        ->twice()
        ->andReturn(null);

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());

    $svc->lookup('10.1/transient-outage');
    $svc->lookup('10.1/transient-outage'); // must re-query, not hit cache
});

it('caches a DataCite hit reached via Crossref error fallback', function () {
    // Even if the primary errored, a positive DataCite hit is safe to cache:
    // we have a confirmed record for that DOI.
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')
        ->once()
        ->andReturn(CitationLookupResult::error('crossref', 'HTTP 500'));

    $datacite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $datacite->shouldReceive('getDataCiteMetadata')
        ->once()
        ->andReturn(['titles' => [['title' => 'Cached fallback hit']]]);

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());

    $svc->lookup('10.1/recovered');
    $r2 = $svc->lookup('10.1/recovered');

    expect($r2->found)->toBeTrue();
    expect($r2->source)->toBe('datacite');
});

it('caches a notFound when the Crossref primary completed cleanly', function () {
    // Crossref returned a clean 404 → DataCite also misses. This is a
    // legitimate negative result and SHOULD be cached.
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')
        ->once()
        ->andReturn(CitationLookupResult::notFound('crossref'));

    $datacite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $datacite->shouldReceive('getDataCiteMetadata')
        ->once()
        ->andReturn(null);

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());

    $r1 = $svc->lookup('10.1/legit-miss');
    $r2 = $svc->lookup('10.1/legit-miss'); // must come from cache

    expect($r1->found)->toBeFalse();
    expect($r2->found)->toBeFalse();
});

describe('DataCite attribute transformation', function () {
    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    function runDataCiteFallback(array $attrs): array
    {
        $crossref = Mockery::mock(CrossrefClient::class);
        $crossref->shouldReceive('lookup')->andReturn(CitationLookupResult::notFound('crossref'));

        $datacite = Mockery::mock(DataCiteApiService::class)->makePartial();
        $datacite->shouldReceive('getDataCiteMetadata')->andReturn($attrs);

        $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());
        $result = $svc->lookup('10.1/transform');

        /** @var array<string, mixed> $data */
        $data = $result->data ?? [];

        return $data;
    }

    it('defaults titleType to MainTitle when missing', function () {
        $data = runDataCiteFallback([
            'titles' => [['title' => 'Bare Title']],
        ]);

        expect($data['titles'][0]['titleType'])->toBe('MainTitle');
    });

    it('skips titles that are not arrays or have a non-string title', function () {
        $data = runDataCiteFallback([
            'titles' => ['not-an-array', ['title' => 42], ['title' => 'Valid']],
        ]);

        expect($data['titles'])->toHaveCount(1);
        expect($data['titles'][0]['title'])->toBe('Valid');
    });

    it('defaults nameType to Personal when missing', function () {
        $data = runDataCiteFallback([
            'creators' => [['name' => 'Anon']],
        ]);

        expect($data['creators'][0]['nameType'])->toBe('Personal');
    });

    it('only takes the first nameIdentifier', function () {
        $data = runDataCiteFallback([
            'creators' => [[
                'nameType' => 'Personal',
                'name' => 'X',
                'nameIdentifiers' => [
                    ['nameIdentifier' => 'FIRST', 'nameIdentifierScheme' => 'ORCID'],
                    ['nameIdentifier' => 'SECOND', 'nameIdentifierScheme' => 'OTHER'],
                ],
            ]],
        ]);

        expect($data['creators'][0]['nameIdentifier'])->toBe('FIRST');
        expect($data['creators'][0]['nameIdentifierScheme'])->toBe('ORCID');
    });

    it('accepts a plain string affiliation', function () {
        $data = runDataCiteFallback([
            'creators' => [[
                'name' => 'X',
                'affiliation' => ['GFZ'],
            ]],
        ]);

        expect($data['creators'][0]['affiliations'][0])->toMatchArray([
            'name' => 'GFZ',
            'affiliationIdentifier' => null,
            'scheme' => null,
        ]);
    });

    it('reads the affiliationIdentifierScheme from DataCite payloads', function () {
        $data = runDataCiteFallback([
            'creators' => [[
                'name' => 'X',
                'affiliation' => [[
                    'name' => 'GFZ',
                    'affiliationIdentifier' => 'https://ror.org/04z8jg394',
                    'affiliationIdentifierScheme' => 'ROR',
                    'schemeUri' => 'https://ror.org',
                ]],
            ]],
        ]);

        expect($data['creators'][0]['affiliations'][0])->toMatchArray([
            'name' => 'GFZ',
            'affiliationIdentifier' => 'https://ror.org/04z8jg394',
            'scheme' => 'ROR',
        ]);
    });

    it('preserves non-ROR schemes such as GRID or ISNI', function () {
        $data = runDataCiteFallback([
            'creators' => [[
                'name' => 'X',
                'affiliation' => [[
                    'name' => 'GFZ',
                    'affiliationIdentifier' => 'grid.123',
                    'affiliationIdentifierScheme' => 'GRID',
                ]],
            ]],
        ]);

        expect($data['creators'][0]['affiliations'][0]['scheme'])->toBe('GRID');
    });

    it('returns scheme null when affiliationIdentifierScheme is absent', function () {
        $data = runDataCiteFallback([
            'creators' => [[
                'name' => 'X',
                'affiliation' => [[
                    'name' => 'GFZ',
                    'affiliationIdentifier' => 'some-id',
                ]],
            ]],
        ]);

        expect($data['creators'][0]['affiliations'][0]['scheme'])->toBeNull();
    });

    it('casts the publicationYear string to int', function () {
        $data = runDataCiteFallback([
            'publicationYear' => '2022',
        ]);

        expect($data['publicationYear'])->toBe(2022);
    });

    it('falls back to the Text type mapper when no resourceTypeGeneral', function () {
        $data = runDataCiteFallback([]);

        expect($data['relatedItemType'])->toBe('Text');
    });

    it('skips non-array creators', function () {
        $data = runDataCiteFallback([
            'creators' => ['not-a-creator', ['name' => 'Valid']],
        ]);

        expect($data['creators'])->toHaveCount(1);
        expect($data['creators'][0]['name'])->toBe('Valid');
    });
});

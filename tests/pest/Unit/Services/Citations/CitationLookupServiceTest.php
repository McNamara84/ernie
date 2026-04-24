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

    $datacite = Mockery::mock(DataCiteApiService::class);
    $datacite->shouldNotReceive('getDataCiteMetadata');

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());
    $result = $svc->lookup('10.1/hit');

    expect($result->source)->toBe('crossref');
    expect($result->found)->toBeTrue();
});

it('falls back to DataCite when Crossref returns notFound', function () {
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')->once()->andReturn(CitationLookupResult::notFound('crossref'));

    $datacite = Mockery::mock(DataCiteApiService::class);
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

    $datacite = Mockery::mock(DataCiteApiService::class);
    $datacite->shouldReceive('getDataCiteMetadata')->andReturn(null);

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());
    $result = $svc->lookup('10.1/missing');

    expect($result->found)->toBeFalse();
    expect($result->source)->toBe('datacite');
});

it('caches the result so repeat calls do not hit the network', function () {
    $crossref = Mockery::mock(CrossrefClient::class);
    $crossref->shouldReceive('lookup')->once()->andReturn(CitationLookupResult::hit('crossref', ['x' => 1]));

    $datacite = Mockery::mock(DataCiteApiService::class);

    $svc = new CitationLookupService($crossref, $datacite, new DataCiteTypeMapper());

    $r1 = $svc->lookup('10.1/CACHED');
    $r2 = $svc->lookup('10.1/cached'); // case-insensitive cache key

    expect($r1->found)->toBeTrue();
    expect($r2->found)->toBeTrue();
});

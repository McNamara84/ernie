<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DataCiteController;
use App\Services\DataCiteApiService;

covers(DataCiteController::class);

describe('getCitation', function () {
    it('returns citation for a valid DOI', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/GFZ.TEST.2024')
            ->once()
            ->andReturn([
                'author' => [
                    ['family' => 'Smith', 'given' => 'John'],
                ],
                'issued' => ['date-parts' => [[2024]]],
                'title' => 'Test Dataset',
                'publisher' => 'GFZ',
                'DOI' => '10.5880/GFZ.TEST.2024',
            ]);

        $mockService->shouldReceive('buildCitationFromMetadata')
            ->once()
            ->andReturn('Smith, John (2024): Test Dataset. GFZ. https://doi.org/10.5880/GFZ.TEST.2024');

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/citation?doi=10.5880/GFZ.TEST.2024');

        $response->assertOk()
            ->assertJsonStructure(['citation', 'doi'])
            ->assertJson([
                'doi' => '10.5880/GFZ.TEST.2024',
                'citation' => 'Smith, John (2024): Test Dataset. GFZ. https://doi.org/10.5880/GFZ.TEST.2024',
            ]);
    });

    it('returns 404 when DOI metadata not found', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/nonexistent')
            ->once()
            ->andReturnNull();

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/citation?doi=10.5880/nonexistent');

        $response->assertNotFound()
            ->assertJson(['error' => 'Metadata not found for DOI']);
    });

    it('returns 422 when doi query parameter is missing', function () {
        $response = $this->getJson('/api/datacite/citation');

        $response->assertStatus(422)
            ->assertJson(['error' => 'Missing or invalid doi query parameter']);
    });

    it('returns 422 when doi query parameter is empty', function () {
        $response = $this->getJson('/api/datacite/citation?doi=');

        $response->assertStatus(422)
            ->assertJson(['error' => 'Missing or invalid doi query parameter']);
    });

    it('returns 422 when doi query parameter is whitespace only', function () {
        $response = $this->getJson('/api/datacite/citation?doi=%20%20%20');

        $response->assertStatus(422)
            ->assertJson(['error' => 'Missing or invalid doi query parameter']);
    });

    it('returns 422 when doi query parameter has invalid format', function () {
        $response = $this->getJson('/api/datacite/citation?doi=not-a-doi');

        $response->assertStatus(422)
            ->assertJson(['error' => 'Missing or invalid doi query parameter']);
    });

    it('handles DOIs with encoded slashes in query parameter correctly', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/GFZ.TEST.2024')
            ->once()
            ->andReturn([
                'author' => [['family' => 'Smith', 'given' => 'John']],
                'issued' => ['date-parts' => [[2024]]],
                'title' => 'Test Dataset',
                'publisher' => 'GFZ',
                'DOI' => '10.5880/GFZ.TEST.2024',
            ]);

        $mockService->shouldReceive('buildCitationFromMetadata')
            ->once()
            ->andReturn('Smith, J. (2024): Test Dataset. GFZ. https://doi.org/10.5880/GFZ.TEST.2024');

        $this->app->instance(DataCiteApiService::class, $mockService);

        // URL-encoded slash in query parameter (as browser sends it)
        $response = $this->getJson('/api/datacite/citation?doi=10.5880%2FGFZ.TEST.2024');

        $response->assertOk()
            ->assertJsonPath('doi', '10.5880/GFZ.TEST.2024');
    });
});

describe('getAuthors', function () {
    it('returns structured author data from DataCite REST API', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/GFZ.TEST.2024')
            ->once()
            ->andReturn([
                'creators' => [
                    [
                        'nameType' => 'Personal',
                        'givenName' => 'John',
                        'familyName' => 'Smith',
                        'nameIdentifiers' => [
                            ['nameIdentifier' => 'https://orcid.org/0000-0002-1234-5678', 'nameIdentifierScheme' => 'ORCID'],
                        ],
                        'affiliation' => [
                            ['name' => 'GFZ Helmholtz Centre', 'affiliationIdentifier' => 'https://ror.org/04z8jg394', 'affiliationIdentifierScheme' => 'ROR'],
                        ],
                    ],
                    [
                        'nameType' => 'Organizational',
                        'name' => 'GFZ Data Services',
                        'nameIdentifiers' => [
                            ['nameIdentifier' => 'https://ror.org/04z8jg394', 'nameIdentifierScheme' => 'ROR'],
                        ],
                        'affiliation' => [],
                    ],
                ],
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors?doi=10.5880/GFZ.TEST.2024');

        $response->assertOk()
            ->assertJsonStructure([
                'doi',
                'authors' => [
                    '*' => ['given_name', 'family_name', 'name', 'orcid', 'type', 'affiliations', 'ror_id'],
                ],
            ])
            ->assertJson([
                'doi' => '10.5880/GFZ.TEST.2024',
                'authors' => [
                    [
                        'given_name' => 'John',
                        'family_name' => 'Smith',
                        'name' => null,
                        'orcid' => '0000-0002-1234-5678',
                        'type' => 'Person',
                        'affiliations' => [
                            ['name' => 'GFZ Helmholtz Centre', 'identifier' => 'https://ror.org/04z8jg394', 'identifier_scheme' => 'ROR'],
                        ],
                        'ror_id' => null,
                    ],
                    [
                        'given_name' => null,
                        'family_name' => null,
                        'name' => 'GFZ Data Services',
                        'orcid' => null,
                        'type' => 'Institution',
                        'affiliations' => [],
                        'ror_id' => 'https://ror.org/04z8jg394',
                    ],
                ],
            ]);
    });

    it('falls back to CSL JSON when DataCite REST API returns null', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/GFZ.TEST.2024')
            ->once()
            ->andReturnNull();
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/GFZ.TEST.2024')
            ->once()
            ->andReturn([
                'author' => [
                    ['family' => 'Smith', 'given' => 'John', 'ORCID' => 'https://orcid.org/0000-0002-1234-5678'],
                    ['family' => 'Doe', 'given' => 'Jane'],
                    ['literal' => 'GFZ Helmholtz Centre'],
                ],
                'title' => 'Test Dataset',
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors?doi=10.5880/GFZ.TEST.2024');

        $response->assertOk()
            ->assertJson([
                'doi' => '10.5880/GFZ.TEST.2024',
                'authors' => [
                    [
                        'given_name' => 'John',
                        'family_name' => 'Smith',
                        'name' => null,
                        'orcid' => '0000-0002-1234-5678',
                        'type' => 'Person',
                        'affiliations' => [],
                        'ror_id' => null,
                    ],
                    [
                        'given_name' => 'Jane',
                        'family_name' => 'Doe',
                        'name' => null,
                        'orcid' => null,
                        'type' => 'Person',
                        'affiliations' => [],
                        'ror_id' => null,
                    ],
                    [
                        'given_name' => null,
                        'family_name' => null,
                        'name' => 'GFZ Helmholtz Centre',
                        'orcid' => null,
                        'type' => 'Institution',
                        'affiliations' => [],
                        'ror_id' => null,
                    ],
                ],
            ]);
    });

    it('returns 404 when both DataCite REST API and CSL JSON fail', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/nonexistent')
            ->once()
            ->andReturnNull();
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/nonexistent')
            ->once()
            ->andReturnNull();

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors?doi=10.5880/nonexistent');

        $response->assertNotFound()
            ->assertJson(['error' => 'Metadata not found for DOI']);
    });

    it('returns empty authors array when DataCite metadata has no creators', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/GFZ.NOAUTHORS')
            ->once()
            ->andReturn([
                'titles' => [['title' => 'Dataset Without Authors']],
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors?doi=10.5880/GFZ.NOAUTHORS');

        $response->assertOk()
            ->assertJson([
                'doi' => '10.5880/GFZ.NOAUTHORS',
                'authors' => [],
            ]);
    });

    it('extracts ORCID from DataCite nameIdentifiers', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/GFZ.ORCID')
            ->once()
            ->andReturn([
                'creators' => [
                    [
                        'nameType' => 'Personal',
                        'givenName' => 'A',
                        'familyName' => 'B',
                        'nameIdentifiers' => [
                            ['nameIdentifier' => 'https://orcid.org/0000-0001-2345-6789', 'nameIdentifierScheme' => 'ORCID'],
                        ],
                        'affiliation' => [],
                    ],
                    [
                        'nameType' => 'Personal',
                        'givenName' => 'C',
                        'familyName' => 'D',
                        'nameIdentifiers' => [
                            ['nameIdentifier' => '0000-0002-3456-789X', 'nameIdentifierScheme' => 'ORCID'],
                        ],
                        'affiliation' => [],
                    ],
                ],
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors?doi=10.5880/GFZ.ORCID');

        $response->assertOk()
            ->assertJson([
                'authors' => [
                    ['family_name' => 'B', 'orcid' => '0000-0001-2345-6789'],
                    ['family_name' => 'D', 'orcid' => '0000-0002-3456-789X'],
                ],
            ]);
    });

    it('extracts ROR ID from organizational creators', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/GFZ.ROR')
            ->once()
            ->andReturn([
                'creators' => [
                    [
                        'nameType' => 'Organizational',
                        'name' => 'GFZ Helmholtz Centre',
                        'nameIdentifiers' => [
                            ['nameIdentifier' => 'https://ror.org/04z8jg394', 'nameIdentifierScheme' => 'ROR'],
                        ],
                        'affiliation' => [],
                    ],
                ],
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors?doi=10.5880/GFZ.ROR');

        $response->assertOk()
            ->assertJson([
                'authors' => [
                    [
                        'name' => 'GFZ Helmholtz Centre',
                        'type' => 'Institution',
                        'ror_id' => 'https://ror.org/04z8jg394',
                    ],
                ],
            ]);
    });

    it('extracts affiliations with ROR identifiers from personal creators', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/GFZ.AFFIL')
            ->once()
            ->andReturn([
                'creators' => [
                    [
                        'nameType' => 'Personal',
                        'givenName' => 'John',
                        'familyName' => 'Doe',
                        'nameIdentifiers' => [],
                        'affiliation' => [
                            [
                                'name' => 'GFZ Helmholtz Centre',
                                'affiliationIdentifier' => 'https://ror.org/04z8jg394',
                                'affiliationIdentifierScheme' => 'ROR',
                            ],
                            [
                                'name' => 'University of Potsdam',
                            ],
                        ],
                    ],
                ],
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors?doi=10.5880/GFZ.AFFIL');

        $response->assertOk()
            ->assertJson([
                'authors' => [
                    [
                        'family_name' => 'Doe',
                        'type' => 'Person',
                        'affiliations' => [
                            ['name' => 'GFZ Helmholtz Centre', 'identifier' => 'https://ror.org/04z8jg394', 'identifier_scheme' => 'ROR'],
                            ['name' => 'University of Potsdam', 'identifier' => null, 'identifier_scheme' => null],
                        ],
                    ],
                ],
            ]);
    });

    it('handles string-only affiliations from older DataCite records', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/GFZ.OLDAFFIL')
            ->once()
            ->andReturn([
                'creators' => [
                    [
                        'nameType' => 'Personal',
                        'givenName' => 'Jane',
                        'familyName' => 'Smith',
                        'nameIdentifiers' => [],
                        'affiliation' => ['GFZ Helmholtz Centre', 'University of Potsdam'],
                    ],
                ],
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors?doi=10.5880/GFZ.OLDAFFIL');

        $response->assertOk()
            ->assertJson([
                'authors' => [
                    [
                        'family_name' => 'Smith',
                        'affiliations' => [
                            ['name' => 'GFZ Helmholtz Centre', 'identifier' => null, 'identifier_scheme' => null],
                            ['name' => 'University of Potsdam', 'identifier' => null, 'identifier_scheme' => null],
                        ],
                    ],
                ],
            ]);
    });

    it('handles bare ROR IDs without URL prefix', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/GFZ.BAREROR')
            ->once()
            ->andReturn([
                'creators' => [
                    [
                        'nameType' => 'Organizational',
                        'name' => 'Test Institution',
                        'nameIdentifiers' => [
                            ['nameIdentifier' => '04z8jg394', 'nameIdentifierScheme' => 'ROR'],
                        ],
                        'affiliation' => [],
                    ],
                ],
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors?doi=10.5880/GFZ.BAREROR');

        // Bare ROR ID is normalized to full URL
        $response->assertOk()
            ->assertJson([
                'authors' => [
                    [
                        'name' => 'Test Institution',
                        'type' => 'Institution',
                        'ror_id' => 'https://ror.org/04z8jg394',
                    ],
                ],
            ]);
    });

    it('returns 422 when doi query parameter is missing', function () {
        $response = $this->getJson('/api/datacite/authors');

        $response->assertStatus(422)
            ->assertJson(['error' => 'Missing or invalid doi query parameter']);
    });

    it('returns 422 when doi query parameter is empty', function () {
        $response = $this->getJson('/api/datacite/authors?doi=');

        $response->assertStatus(422)
            ->assertJson(['error' => 'Missing or invalid doi query parameter']);
    });

    it('returns 422 when doi query parameter is whitespace only', function () {
        $response = $this->getJson('/api/datacite/authors?doi=%20%20%20');

        $response->assertStatus(422)
            ->assertJson(['error' => 'Missing or invalid doi query parameter']);
    });

    it('returns 422 when doi query parameter has invalid format', function () {
        $response = $this->getJson('/api/datacite/authors?doi=not-a-doi');

        $response->assertStatus(422)
            ->assertJson(['error' => 'Missing or invalid doi query parameter']);
    });

    it('handles DOIs with encoded slashes in query parameter correctly', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getDataCiteMetadata')
            ->with('10.5880/GFZ.TEST.2024')
            ->once()
            ->andReturn([
                'creators' => [
                    [
                        'nameType' => 'Personal',
                        'givenName' => 'John',
                        'familyName' => 'Smith',
                        'nameIdentifiers' => [],
                        'affiliation' => [],
                    ],
                ],
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        // URL-encoded slash in query parameter (as browser sends it)
        $response = $this->getJson('/api/datacite/authors?doi=10.5880%2FGFZ.TEST.2024');

        $response->assertOk()
            ->assertJsonPath('doi', '10.5880/GFZ.TEST.2024');
    });
});

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

        $response = $this->getJson('/api/datacite/citation/10.5880/GFZ.TEST.2024');

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

        $response = $this->getJson('/api/datacite/citation/10.5880/nonexistent');

        $response->assertNotFound()
            ->assertJson(['error' => 'Metadata not found for DOI']);
    });
});

describe('getAuthors', function () {
    it('returns structured author data for a valid DOI', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
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

        $response = $this->getJson('/api/datacite/authors/10.5880/GFZ.TEST.2024');

        $response->assertOk()
            ->assertJsonStructure([
                'doi',
                'authors' => [
                    '*' => ['given_name', 'family_name', 'name', 'orcid'],
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
                    ],
                    [
                        'given_name' => 'Jane',
                        'family_name' => 'Doe',
                        'name' => null,
                        'orcid' => null,
                    ],
                    [
                        'given_name' => null,
                        'family_name' => null,
                        'name' => 'GFZ Helmholtz Centre',
                        'orcid' => null,
                    ],
                ],
            ]);
    });

    it('returns 404 when DOI metadata not found', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/nonexistent')
            ->once()
            ->andReturnNull();

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors/10.5880/nonexistent');

        $response->assertNotFound()
            ->assertJson(['error' => 'Metadata not found for DOI']);
    });

    it('returns empty authors array when metadata has no author field', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/GFZ.NOAUTHORS')
            ->once()
            ->andReturn([
                'title' => 'Dataset Without Authors',
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors/10.5880/GFZ.NOAUTHORS');

        $response->assertOk()
            ->assertJson([
                'doi' => '10.5880/GFZ.NOAUTHORS',
                'authors' => [],
            ]);
    });

    it('extracts ORCID from various formats', function () {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/GFZ.ORCID')
            ->once()
            ->andReturn([
                'author' => [
                    ['family' => 'A', 'given' => 'B', 'ORCID' => 'https://orcid.org/0000-0001-2345-6789'],
                    ['family' => 'C', 'given' => 'D', 'orcid' => '0000-0002-3456-789X'],
                    ['family' => 'E', 'given' => 'F', 'ORCID' => 'invalid-orcid'],
                    ['family' => 'G', 'given' => 'H', 'ORCID' => ''],
                ],
            ]);

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/authors/10.5880/GFZ.ORCID');

        $response->assertOk()
            ->assertJson([
                'authors' => [
                    ['family_name' => 'A', 'orcid' => '0000-0001-2345-6789'],
                    ['family_name' => 'C', 'orcid' => '0000-0002-3456-789X'],
                    ['family_name' => 'E', 'orcid' => null],
                    ['family_name' => 'G', 'orcid' => null],
                ],
            ]);
    });
});

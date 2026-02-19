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

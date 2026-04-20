<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DataCiteController;
use App\Services\DataCiteApiService;

covers(DataCiteController::class);

describe('GET /api/datacite/citation', function (): void {
    test('returns citation for valid DOI', function (): void {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/test.2024.001')
            ->andReturn([
                'data' => [
                    'attributes' => [
                        'creators' => [['name' => 'Doe, J.']],
                        'titles' => [['title' => 'Test Dataset']],
                        'publicationYear' => 2024,
                    ],
                ],
            ]);

        $mockService->shouldReceive('buildCitationFromMetadata')
            ->andReturn('Doe, J. (2024): Test Dataset.');

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/citation?doi=10.5880/test.2024.001');

        $response->assertOk()
            ->assertJsonPath('doi', '10.5880/test.2024.001')
            ->assertJsonPath('citation', 'Doe, J. (2024): Test Dataset.');
    });

    test('returns 404 when DOI not found', function (): void {
        $mockService = Mockery::mock(DataCiteApiService::class);
        $mockService->shouldReceive('getMetadata')
            ->with('10.5880/nonexistent')
            ->andReturnNull();

        $this->app->instance(DataCiteApiService::class, $mockService);

        $response = $this->getJson('/api/datacite/citation?doi=10.5880/nonexistent');

        $response->assertNotFound()
            ->assertJsonPath('error', 'Metadata not found for DOI');
    });

    test('returns 422 when doi query parameter is missing', function (): void {
        $response = $this->getJson('/api/datacite/citation');

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Missing or invalid doi query parameter');
    });
});

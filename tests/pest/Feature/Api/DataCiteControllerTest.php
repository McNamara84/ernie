<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DataCiteController;
use App\Services\Citations\LegacyCitationCacheService;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\DataCiteApiService;

covers(DataCiteController::class);

beforeEach(function (): void {
    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('find')->zeroOrMoreTimes()->andReturnNull();
    $this->app->instance(LegacyCitationCacheService::class, $legacy);
});

describe('GET /api/datacite/citation', function (): void {
    test('returns citation for valid DOI', function (): void {
        $mockService = Mockery::mock(DataCiteApiService::class)->makePartial();
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

    test('returns a legacy citation without requesting DOI metadata', function (): void {
        $mockService = Mockery::mock(DataCiteApiService::class)->makePartial();
        $mockService->shouldNotReceive('getMetadata');
        $mockService->shouldNotReceive('buildCitationFromMetadata');
        $this->app->instance(DataCiteApiService::class, $mockService);

        $citationLabels = Mockery::mock(RelatedIdentifierCitationLabelService::class);
        $citationLabels->shouldReceive('resolve')
            ->once()
            ->with('10.1007/978-94-015-7879-0', 'DOI')
            ->andReturn('Cook, E., Kairiukstis, Leonardas, 1990. Methods of dendrochronology.');
        $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationLabels);

        $response = $this->getJson('/api/datacite/citation?doi=10.1007/978-94-015-7879-0');

        $response->assertOk()
            ->assertJsonPath('doi', '10.1007/978-94-015-7879-0')
            ->assertJsonPath(
                'citation',
                'Cook, E., Kairiukstis, Leonardas, 1990. Methods of dendrochronology.',
            );
    });

    test('returns 404 when DOI not found', function (): void {
        $mockService = Mockery::mock(DataCiteApiService::class)->makePartial();
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

    test('returns 422 when doi is whitespace only', function (): void {
        $response = $this->getJson('/api/datacite/citation?doi=%20%20%20');

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Missing or invalid doi query parameter');
    });

    test('returns 422 when doi has invalid format', function (): void {
        $response = $this->getJson('/api/datacite/citation?doi=not-a-doi');

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Missing or invalid doi query parameter');
    });
});

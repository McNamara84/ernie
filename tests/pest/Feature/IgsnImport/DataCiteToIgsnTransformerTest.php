<?php

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\DataCiteToIgsnTransformer;
use App\Services\DataCiteToResourceTransformer;
use App\Support\GeoLocations\GeoLocationWriteReport;

beforeEach(function () {
    $this->baseTransformer = Mockery::mock(DataCiteToResourceTransformer::class);
    $this->transformer = new DataCiteToIgsnTransformer($this->baseTransformer);
});

afterEach(function () {
    Mockery::close();
});

describe('DataCiteToIgsnTransformer', function () {
    it('creates Resource and IgsnMetadata via base transformer', function () {
        $resource = Resource::factory()->create();

        $doiData = [
            'attributes' => [
                'doi' => '10.60510/GFTRANSFORM001',
                'titles' => [['title' => 'Test IGSN']],
                'publicationYear' => 2024,
                'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
            ],
        ];

        $this->baseTransformer
            ->shouldReceive('transform')
            ->once()
            ->with($doiData, 1, null)
            ->andReturn($resource);

        $result = $this->transformer->transform($doiData, 1);

        expect($result->id)->toBe($resource->id);

        $igsnMetadata = IgsnMetadata::where('resource_id', $resource->id)->first();
        expect($igsnMetadata)->not->toBeNull();
        expect($igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
    });

    it('passes the operation report to the base transformer', function () {
        $resource = Resource::factory()->create();
        $report = new GeoLocationWriteReport;
        $this->baseTransformer->shouldReceive('transform')->once()
            ->with(['attributes' => []], 1, $report)
            ->andReturnUsing(function () use ($resource, $report): Resource {
                $report->removedCount = 1;

                return $resource;
            });

        $result = $this->transformer->transform(['attributes' => []], 1, $report);
        expect($result->id)->toBe($resource->id)->and($report->removedCount)->toBe(1);
    });

    it('sets upload_status to registered on the IgsnMetadata', function () {
        $resource = Resource::factory()->create();

        $this->baseTransformer
            ->shouldReceive('transform')
            ->once()
            ->andReturn($resource);

        $this->transformer->transform(['attributes' => []], 1);

        $igsnMetadata = IgsnMetadata::where('resource_id', $resource->id)->first();
        expect($igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
    });
});

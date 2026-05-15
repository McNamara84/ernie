<?php

declare(strict_types=1);

use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\DataCiteApiService;

covers(RelatedIdentifierCitationLabelService::class);

it('resolves a citation label for DOI identifiers', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1234/example')->andReturn('10.1234/example');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/example')->andReturn(['title' => 'Example']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->with(['title' => 'Example'])->andReturn('Doe, J. (2026): Example. Publisher.');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('10.1234/example', 'DOI'))->toBe('Doe, J. (2026): Example. Publisher.');
});

it('resolves a citation label for DOI resolver URLs stored as URL', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('https://doi.org/10.1234/example')->andReturn('10.1234/example');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/example')->andReturn(['title' => 'Example']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->with(['title' => 'Example'])->andReturn('Doe, J. (2026): Example. Publisher.');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('https://doi.org/10.1234/example', 'URL'))->toBe('Doe, J. (2026): Example. Publisher.');
});

it('returns null for non DOI-like URL identifiers', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('https://example.org/page')->andReturn('https://example.org/page');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('https://example.org/page', 'URL'))->toBeNull();
});

it('returns null for unsupported identifier types', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('1234/5678', 'Handle'))->toBeNull();
});

it('returns null when metadata lookup fails', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1234/missing')->andReturn('10.1234/missing');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/missing')->andReturnNull();
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('10.1234/missing', 'DOI'))->toBeNull();
});
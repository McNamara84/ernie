<?php

declare(strict_types=1);

use App\Services\MslLaboratoryVocabularyService;
use App\Support\MslLaboratoryService;

covers(MslLaboratoryService::class);

beforeEach(function (): void {
    $this->vocabularyService = Mockery::mock(MslLaboratoryVocabularyService::class);
    $this->payload = [
        'version' => '1.2',
        'lastUpdated' => '2026-07-21T12:00:00+00:00',
        'total' => 1,
        'source' => [
            'repository' => 'UtrechtUniversity/msl_vocabularies',
            'ref' => 'main',
            'path' => 'vocabularies/labs/1.1/laboratories.json',
            'sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ],
        'data' => [
            [
                'identifier' => 'lab-001',
                'name' => 'Rock Physics Lab',
                'display_name' => 'Rock Physics Lab — GFZ',
                'affiliation_name' => 'GFZ Helmholtz Centre',
                'affiliation_ror' => 'https://ror.org/04z8jg394',
                'scientific_domain' => 'Geosciences',
                'country' => 'Germany',
            ],
        ],
    ];
});

it('indexes the complete local vocabulary entry by identifier', function (): void {
    $this->vocabularyService->shouldReceive('getLocalPayload')
        ->once()
        ->andReturn($this->payload);

    $laboratory = (new MslLaboratoryService($this->vocabularyService))->findByLabId('lab-001');

    expect($laboratory)->toMatchArray($this->payload['data'][0])
        ->and($laboratory)->toHaveKeys([
            'identifier', 'name', 'display_name', 'affiliation_name',
            'affiliation_ror', 'scientific_domain', 'country',
        ]);
});

it('uses its request-local identifier index until it is cleared', function (): void {
    $this->vocabularyService->shouldReceive('getLocalPayload')
        ->twice()
        ->andReturn($this->payload);

    $service = new MslLaboratoryService($this->vocabularyService);

    expect($service->isValidLabId('lab-001'))->toBeTrue()
        ->and($service->findByLabId('unknown'))->toBeNull();

    $service->clearCache();

    expect($service->findByLabId('lab-001'))->not->toBeNull();
});

it('returns no match when the local vocabulary is missing or unreadable', function (mixed $payload): void {
    $expectation = $this->vocabularyService->shouldReceive('getLocalPayload')->once();

    if ($payload instanceof Throwable) {
        $expectation->andThrow($payload);
    } else {
        $expectation->andReturn($payload);
    }

    $service = new MslLaboratoryService($this->vocabularyService);

    expect($service->findByLabId('lab-001'))->toBeNull();
})->with([
    'missing file' => null,
    'unreadable file' => new RuntimeException('Unreadable local vocabulary'),
]);

it('enriches stored fields from the local vocabulary', function (): void {
    $this->vocabularyService->shouldReceive('getLocalPayload')
        ->once()
        ->andReturn($this->payload);

    $result = (new MslLaboratoryService($this->vocabularyService))->enrichLaboratoryData(
        'lab-001',
        'Uploaded name',
        'Uploaded affiliation',
        'https://ror.org/uploaded'
    );

    expect($result)->toBe([
        'identifier' => 'lab-001',
        'name' => 'Rock Physics Lab',
        'affiliation_name' => 'GFZ Helmholtz Centre',
        'affiliation_ror' => 'https://ror.org/04z8jg394',
    ]);
});

it('preserves upload fallback values for a historical unknown identifier', function (): void {
    $this->vocabularyService->shouldReceive('getLocalPayload')
        ->once()
        ->andReturn($this->payload);

    $result = (new MslLaboratoryService($this->vocabularyService))->enrichLaboratoryData(
        'historical-lab',
        'Historical Laboratory',
        'Former University',
        'https://ror.org/historical'
    );

    expect($result)->toBe([
        'identifier' => 'historical-lab',
        'name' => 'Historical Laboratory',
        'affiliation_name' => 'Former University',
        'affiliation_ror' => 'https://ror.org/historical',
    ]);
});

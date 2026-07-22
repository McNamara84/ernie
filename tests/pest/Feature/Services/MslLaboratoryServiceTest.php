<?php

declare(strict_types=1);

use App\Support\MslLaboratoryService;
use Illuminate\Support\Facades\Storage;

covers(MslLaboratoryService::class);

beforeEach(function (): void {
    Storage::fake('local');
    config(['msl.laboratories_storage_path' => 'msl-laboratories.json']);

    Storage::put('msl-laboratories.json', json_encode([
        'version' => '1.2',
        'lastUpdated' => '2026-07-21T12:00:00+00:00',
        'total' => 1,
        'source' => [
            'repository' => 'UtrechtUniversity/msl_vocabularies',
            'ref' => 'main',
            'path' => 'vocabularies/labs/1.2/laboratories.json',
            'sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ],
        'data' => [
            [
                'identifier' => 'test123',
                'name' => 'Test Lab',
                'display_name' => 'Test Lab — Test University',
                'affiliation_name' => 'Test University',
                'affiliation_ror' => 'https://ror.org/012345678',
                'scientific_domain' => 'Materials Science',
                'country' => 'Netherlands',
            ],
        ],
    ], JSON_THROW_ON_ERROR));
});

it('looks up a laboratory exclusively from the local vocabulary file', function (): void {
    $laboratory = app(MslLaboratoryService::class)->findByLabId('test123');

    expect($laboratory)->not->toBeNull()
        ->and($laboratory['name'])->toBe('Test Lab')
        ->and($laboratory['display_name'])->toBe('Test Lab — Test University')
        ->and($laboratory['scientific_domain'])->toBe('Materials Science')
        ->and($laboratory['country'])->toBe('Netherlands');
});

it('uses local vocabulary values when enriching an upload', function (): void {
    $result = app(MslLaboratoryService::class)->enrichLaboratoryData(
        'test123',
        'Uploaded Lab',
        'Uploaded University',
        'https://ror.org/uploaded'
    );

    expect($result['name'])->toBe('Test Lab')
        ->and($result['affiliation_name'])->toBe('Test University')
        ->and($result['affiliation_ror'])->toBe('https://ror.org/012345678');
});

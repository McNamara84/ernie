<?php

declare(strict_types=1);

use App\Services\SchemaOrg\CreativeWorkMetadataService;
use App\Services\SchemaOrg\DatasetMetadataService;
use App\Services\SchemaOrg\DescribedObjectMetadataService;
use App\Services\SchemaOrg\MediaObjectMetadataService;
use App\Services\SchemaOrg\SoftwareMetadataService;

covers(CreativeWorkMetadataService::class, DatasetMetadataService::class, DescribedObjectMetadataService::class, MediaObjectMetadataService::class, SoftwareMetadataService::class);

function schemaOrgMetadataContent(): array
{
    return [
        'mimeType' => 'application/zip',
        'contentLinks' => [
            ['url' => 'https://example.org/archive.zip', 'mimeType' => 'application/zip', 'contentSize' => '4096'],
            ['url' => 'https://example.org/content.pdf', 'mimeType' => 'application/pdf', 'contentSize' => null],
        ],
        'repositories' => ['https://example.org/source', 'https://example.org/mirror'],
    ];
}

it('keeps MIME types and optional sizes with their own file for every content profile', function (string $service, string $property, string $mediaType): void {
    $metadata = app($service)->map(['name' => 'Research output'], schemaOrgMetadataContent());
    expect($metadata[$property])->toBe([
        ['@type' => $mediaType, 'contentUrl' => 'https://example.org/archive.zip', 'encodingFormat' => 'application/zip', 'contentSize' => '4096'],
        ['@type' => $mediaType, 'contentUrl' => 'https://example.org/content.pdf', 'encodingFormat' => 'application/pdf'],
    ]);
    if ($service !== DatasetMetadataService::class) {
        expect($metadata)->not->toHaveKey('distribution');
    }
    if ($service !== SoftwareMetadataService::class) {
        expect($metadata)->not->toHaveKeys(['codeRepository', 'downloadUrl']);
    }
})->with([
    [CreativeWorkMetadataService::class, 'associatedMedia', 'MediaObject'],
    [DatasetMetadataService::class, 'distribution', 'DataDownload'],
    [SoftwareMetadataService::class, 'associatedMedia', 'DataDownload'],
    [MediaObjectMetadataService::class, 'encoding', 'MediaObject'],
]);

it('omits content assertions when no usable descriptor or download exists', function (string $service, bool $withoutDescriptor): void {
    $empty = ['mimeType' => null, 'contentLinks' => [], 'repositories' => []];
    expect(app($service)->map(['name' => 'Research output'], $withoutDescriptor ? null : $empty))
        ->toBe(['name' => 'Research output']);
})->with([CreativeWorkMetadataService::class, DatasetMetadataService::class, SoftwareMetadataService::class, MediaObjectMetadataService::class])
    ->with([true, false]);

it('preserves single and multiple software content and repository URLs', function (bool $multiple): void {
    $content = schemaOrgMetadataContent();
    if (! $multiple) {
        $content['contentLinks'] = [$content['contentLinks'][0]];
        $content['repositories'] = [$content['repositories'][0]];
    }
    $metadata = app(SoftwareMetadataService::class)->map([], $content);
    expect($metadata['codeRepository'])->toBe($multiple ? ['https://example.org/source', 'https://example.org/mirror'] : 'https://example.org/source')
        ->and($metadata['downloadUrl'])->toBe($multiple ? ['https://example.org/archive.zip', 'https://example.org/content.pdf'] : 'https://example.org/archive.zip');
})->with([true, false]);

it('preserves software repositories without falsely asserting a direct download', function (): void {
    $content = ['mimeType' => null, 'contentLinks' => [], 'repositories' => ['https://example.org/source']];
    expect(app(SoftwareMetadataService::class)->map([], $content))->toBe(['codeRepository' => 'https://example.org/source']);
});

it('preserves bibliographic metadata on a distinct description without assigning it to a physical object', function (): void {
    $bibliographic = [
        'creator' => ['@list' => [['@type' => 'Person', 'name' => 'First creator'], ['@type' => 'Organization', 'name' => 'Second creator']]],
        'publisher' => ['@type' => 'Organization', 'name' => 'Repository'],
        'provider' => ['@id' => 'https://example.org/repository'],
        'datePublished' => '2025-12-03',
        'dateCreated' => '2024-01-01',
        'dateModified' => '2025-12-01',
        'keywords' => ['geology', ['@type' => 'DefinedTerm', 'name' => 'Rock']],
        'license' => 'https://spdx.org/licenses/CC-BY-4.0',
        'spatialCoverage' => ['@type' => 'Place', 'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 52, 'longitude' => 13]],
        'temporalCoverage' => '2024-01-01/..',
        'funding' => [['@type' => 'MonetaryGrant', 'name' => 'Research grant']],
        'version' => '2.0',
        'citation' => [['@type' => 'CreativeWork', 'name' => 'Related publication']],
    ];
    $metadata = [
        '@context' => 'https://schema.org/',
        '@type' => 'Thing',
        '@id' => 'https://doi.org/10.60510/sample',
        'identifier' => ['@type' => 'PropertyValue', 'value' => 'doi:10.60510/sample'],
        'url' => 'https://example.org/sample',
        'name' => 'Physical sample',
        'description' => 'A rock sample.',
        'additionalType' => 'PhysicalObject',
        'conditionsOfAccess' => 'Restricted access',
        'isAccessibleForFree' => false,
        'subjectOf' => [['@type' => 'DataDownload', 'contentUrl' => 'https://example.org/metadata.xml']],
        ...$bibliographic,
    ];
    $object = app(DescribedObjectMetadataService::class)->map($metadata, schemaOrgMetadataContent());
    $description = $object['subjectOf'][1];
    expect($object['@type'])->toBe('Thing')
        ->and($object['@id'])->toBe($metadata['@id'])
        ->and($object['identifier'])->toBe($metadata['identifier'])
        ->and($object['additionalType'])->toBe('PhysicalObject')
        ->and($object['subjectOf'][0])->toBe($metadata['subjectOf'][0])
        ->and($description['@type'])->toBe('CreativeWork')
        ->and($description['@id'])->toBe('https://example.org/sample#resource-description')
        ->and($description['about'])->toBe(['@id' => $metadata['@id']])
        ->and($description['conditionsOfAccess'])->toBe('Access to the described resource: Restricted access')
        ->and($description)->not->toHaveKeys(['identifier', 'isAccessibleForFree', 'additionalType'])
        ->and($description['associatedMedia'][0]['contentSize'])->toBe('4096');
    foreach ($bibliographic as $property => $value) {
        expect($object)->not->toHaveKey($property)
            ->and($description[$property])->toBe($value);
    }
    foreach (['conditionsOfAccess', 'isAccessibleForFree', 'distribution', 'associatedMedia', 'codeRepository'] as $property) {
        expect($object)->not->toHaveKey($property);
    }
});

it('supports object descriptions with only a DOI or with no URL and identity', function (bool $withDoi): void {
    $metadata = ['@type' => 'Thing', 'name' => 'Sample'];
    if ($withDoi) {
        $metadata['@id'] = 'https://doi.org/10.60510/sample';
    }
    $object = app(DescribedObjectMetadataService::class)->map($metadata, null);
    $description = $object['subjectOf'][0];
    expect($description['@type'])->toBe('CreativeWork')
        ->and($description['name'])->toBe('Metadata description of Sample')
        ->and($description)->not->toHaveKeys(['identifier', 'conditionsOfAccess', 'associatedMedia', 'isAccessibleForFree']);
    if ($withDoi) {
        expect($description['@id'])->toBe('https://doi.org/10.60510/sample#resource-description')
            ->and($description['about'])->toBe(['@id' => $metadata['@id']]);
    } else {
        expect($description)->not->toHaveKeys(['@id', 'about']);
    }
})->with([true, false]);

<?php

declare(strict_types=1);

use App\Support\DataCiteSchemaVersion;

covers(DataCiteSchemaVersion::class);

it('recognizes Kernel 4 major and minor URIs', function (string $schemaVersion): void {
    expect(DataCiteSchemaVersion::isKernel4($schemaVersion))->toBeTrue();
})->with([
    DataCiteSchemaVersion::KERNEL_4,
    'https://datacite.org/schema/kernel-4.7',
    'http://datacite.org/schema/kernel-4/',
]);

it('recognizes supported legacy schema representations', function (?string $schemaVersion): void {
    expect(DataCiteSchemaVersion::isKnownLegacy($schemaVersion))->toBeTrue();
})->with([
    null,
    '',
    '3',
    '2.2',
    'http://datacite.org/schema/kernel-3',
    'https://datacite.org/schema/kernel-2.2/',
]);

it('rejects unknown and current versions as known legacy schemas', function (string $schemaVersion): void {
    expect(DataCiteSchemaVersion::isKnownLegacy($schemaVersion))->toBeFalse();
})->with([
    DataCiteSchemaVersion::KERNEL_4,
    'http://datacite.org/schema/kernel-5',
    'legacy-custom',
]);

it('validates Kernel 4 resource types exactly', function (): void {
    expect(DataCiteSchemaVersion::isKernel4ResourceType('Dataset'))->toBeTrue()
        ->and(DataCiteSchemaVersion::isKernel4ResourceType('PhysicalObject'))->toBeTrue()
        ->and(DataCiteSchemaVersion::isKernel4ResourceType('dataset'))->toBeFalse()
        ->and(DataCiteSchemaVersion::isKernel4ResourceType('Funder'))->toBeFalse();
});

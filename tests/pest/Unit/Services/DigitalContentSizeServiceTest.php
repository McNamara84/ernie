<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Size;
use App\Services\SizeFormat\DigitalContentSizeService;

covers(DigitalContentSizeService::class);

test('converts supported decimal and IEC storage units exactly', function (string $value, string $unit, string $bytes): void {
    $size = new Size(['numeric_value' => $value, 'unit' => $unit]);

    expect(app(DigitalContentSizeService::class)->toBytes($size))->toBe($bytes);
})->with([
    ['1', 'B', '1'],
    ['1.5', 'kB', '1500'],
    ['2.25', 'MB', '2250000'],
    ['3', 'GB', '3000000000'],
    ['0.5', 'KiB', '512'],
    ['1.5', 'MiB', '1572864'],
    ['9999999999999999', 'PB', '9999999999999999000000000000000'],
]);

test('rejects zero, non-integral byte results, malformed values, and physical units', function (mixed $value, ?string $unit): void {
    $size = new Size(['numeric_value' => $value, 'unit' => $unit]);

    expect(app(DigitalContentSizeService::class)->toBytes($size))->toBeNull();
})->with([
    ['0', 'MB'],
    ['0.0001', 'B'],
    ['1.2.3', 'MB'],
    ['-1', 'MB'],
    ['12', 'mm'],
    ['12', 'b'],
    [null, 'MB'],
]);

test('accepts only sizes owned by a non-IGSN resource', function (): void {
    $dataset = Resource::factory()->create();
    $size = $dataset->sizes()->create(['numeric_value' => 4, 'unit' => 'MB']);
    $other = Resource::factory()->create();
    $service = app(DigitalContentSizeService::class);

    expect($service->forResource($size, $dataset))->toBe('4000000')
        ->and($service->forResource($size, $other))->toBeNull();

    $physicalObject = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
    $dataset->update(['resource_type_id' => $physicalObject->id]);

    expect($service->forResource($size, $dataset->fresh()))->toBeNull();
});

<?php

declare(strict_types=1);

use App\Models\ResourceInstrument;
use App\Models\Resource;
use App\Models\Setting;

covers(ResourceInstrument::class, Setting::class);

describe('ResourceInstrument', function (): void {
    test('has fillable attributes', function (): void {
        $instrument = new ResourceInstrument;

        expect($instrument->getFillable())->toContain(
            'resource_id',
            'instrument_pid',
            'instrument_pid_type',
            'instrument_name',
            'position',
        );
    });

    test('belongs to a resource', function (): void {
        $resource = Resource::factory()->create();
        $instrument = ResourceInstrument::create([
            'resource_id' => $resource->id,
            'instrument_pid' => 'https://hdl.handle.net/21.T11998/0000-001A-3905-F',
            'instrument_pid_type' => 'Handle',
            'instrument_name' => 'Test Instrument',
            'position' => 1,
        ]);

        expect($instrument->resource)->toBeInstanceOf(Resource::class);
        expect($instrument->resource->id)->toBe($resource->id);
    });

    test('casts position to integer', function (): void {
        $instrument = new ResourceInstrument;
        $casts = $instrument->getCasts();

        expect($casts)->toHaveKey('position');
    });
});

describe('Setting', function (): void {
    test('has fillable attributes', function (): void {
        $setting = new Setting;

        expect($setting->getFillable())->toContain('key', 'value');
    });

    test('has no timestamps', function (): void {
        $setting = new Setting;

        expect($setting->timestamps)->toBeFalse();
    });

    test('getValue returns value for existing key', function (): void {
        Setting::create(['key' => 'test_key', 'value' => 'test_value']);

        expect(Setting::getValue('test_key'))->toBe('test_value');
    });

    test('getValue returns default for missing key', function (): void {
        expect(Setting::getValue('non_existent', 'fallback'))->toBe('fallback');
    });

    test('getValue returns null by default for missing key', function (): void {
        expect(Setting::getValue('non_existent'))->toBeNull();
    });

    test('has default limit constant', function (): void {
        expect(Setting::DEFAULT_LIMIT)->toBe(99);
    });
});

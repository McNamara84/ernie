<?php

declare(strict_types=1);

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('getValue', function () {
    test('returns value for existing key', function () {
        Setting::create(['key' => 'site_name', 'value' => 'ERNIE']);

        expect(Setting::getValue('site_name'))->toBe('ERNIE');
    });

    test('returns default when key does not exist', function () {
        expect(Setting::getValue('nonexistent', 'fallback'))->toBe('fallback');
    });

    test('returns null as default when key missing and no default given', function () {
        expect(Setting::getValue('missing_key'))->toBeNull();
    });

    test('returns value even when it is empty string', function () {
        Setting::create(['key' => 'empty_val', 'value' => '']);

        // empty string is falsy but still a value
        expect(Setting::getValue('empty_val', 'default'))->toBe('');
    });
});

describe('DEFAULT_LIMIT constant', function () {
    test('is 99', function () {
        expect(Setting::DEFAULT_LIMIT)->toBe(99);
    });
});

describe('model configuration', function () {
    test('has no timestamps', function () {
        $setting = new Setting;

        expect($setting->timestamps)->toBeFalse();
    });

    test('key and value are fillable', function () {
        $setting = Setting::create(['key' => 'test_key', 'value' => 'test_value']);

        expect($setting->key)->toBe('test_key')
            ->and($setting->value)->toBe('test_value');
    });
});

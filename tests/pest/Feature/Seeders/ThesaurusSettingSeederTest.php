<?php

declare(strict_types=1);

use App\Models\ThesaurusSetting;
use Database\Seeders\ThesaurusSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds every centralized thesaurus definition idempotently', function (): void {
    $this->seed(ThesaurusSettingSeeder::class);
    $this->seed(ThesaurusSettingSeeder::class);

    foreach (ThesaurusSetting::definitions() as $type => $displayName) {
        expect(ThesaurusSetting::query()->where('type', $type)->count())->toBe(1);
        $this->assertDatabaseHas('thesaurus_settings', [
            'type' => $type,
            'display_name' => $displayName,
        ]);
    }
});

it('does not reset existing ERNIE or ELMO activation choices', function (): void {
    ThesaurusSetting::query()
        ->where('type', ThesaurusSetting::TYPE_MSL_LABORATORIES)
        ->update(['is_active' => false, 'is_elmo_active' => false]);

    $this->seed(ThesaurusSettingSeeder::class);

    $setting = ThesaurusSetting::query()
        ->where('type', ThesaurusSetting::TYPE_MSL_LABORATORIES)
        ->firstOrFail();

    expect($setting->is_active)->toBeFalse()
        ->and($setting->is_elmo_active)->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Models\PidSetting;

it('renames only the default PIDINST label while preserving activation and custom labels', function (): void {
    $migration = require base_path('database/migrations/2026_11_17_000001_update_pidinst_display_name.php');
    $setting = PidSetting::updateOrCreate(['type' => 'pid4inst'], ['display_name' => 'PID4INST (b2inst)', 'is_active' => false, 'is_elmo_active' => true, 'is_elmo_msl_active' => false]);
    $migration->up();
    expect($setting->refresh()->toArray())->toMatchArray(['display_name' => 'PIDINST (B2INST + DataCite)', 'is_active' => false, 'is_elmo_active' => true, 'is_elmo_msl_active' => false]);
    $migration->down();
    expect($setting->refresh()->display_name)->toBe('PID4INST (b2inst)');
    $setting->update(['display_name' => 'Custom instrument registry']);
    $migration->up();
    $migration->down();
    expect($setting->refresh()->display_name)->toBe('Custom instrument registry');
});

<?php

declare(strict_types=1);

use App\Console\Commands\GetMslLaboratories;
use App\Models\ThesaurusSetting;
use App\Services\MslLaboratoryVocabularyService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
covers(GetMslLaboratories::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('stores the resolved version after a successful vocabulary update', function (): void {
    $setting = ThesaurusSetting::query()->updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_MSL_LABORATORIES],
        [
            'display_name' => 'MSL Laboratories',
            'is_active' => false,
            'is_elmo_active' => false,
            'version' => '1.0',
        ]
    );
    $service = Mockery::mock(MslLaboratoryVocabularyService::class);
    $service->shouldReceive('updateLocal')->once()->andReturn([
        'version' => '1.2',
        'lastUpdated' => '2026-07-21T12:00:00+00:00',
        'total' => 119,
        'source' => [
            'repository' => 'UtrechtUniversity/msl_vocabularies',
            'ref' => 'main',
            'path' => 'vocabularies/labs/1.2/laboratories.json',
            'sha' => 'remote-sha',
        ],
        'data' => [],
    ]);
    $this->app->instance(MslLaboratoryVocabularyService::class, $service);

    $this->artisan('get-msl-laboratories')
        ->expectsOutputToContain('MSL laboratories vocabulary updated successfully.')
        ->assertExitCode(Command::SUCCESS);

    $setting->refresh();
    expect($setting->version)->toBe('1.2')
        ->and($setting->is_active)->toBeFalse()
        ->and($setting->is_elmo_active)->toBeFalse();
});

it('returns failure and leaves the stored version unchanged when updating fails', function (): void {
    $setting = ThesaurusSetting::query()->updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_MSL_LABORATORIES],
        [
            'display_name' => 'MSL Laboratories',
            'is_active' => true,
            'is_elmo_active' => true,
            'version' => '1.1',
        ]
    );
    $service = Mockery::mock(MslLaboratoryVocabularyService::class);
    $service->shouldReceive('updateLocal')->once()
        ->andThrow(new RuntimeException('download failed'));
    $this->app->instance(MslLaboratoryVocabularyService::class, $service);

    $this->artisan('get-msl-laboratories')
        ->expectsOutputToContain('Failed to update MSL laboratories: download failed')
        ->assertExitCode(Command::FAILURE);

    expect($setting->fresh()->version)->toBe('1.1');
});

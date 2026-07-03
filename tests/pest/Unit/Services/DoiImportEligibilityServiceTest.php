<?php

declare(strict_types=1);

use App\Services\DoiImportEligibilityService;
use App\Services\DoiSuggestionService;
use App\Services\LegacyResourceLookupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Config::set('datacite.production.prefixes', ['10.5880', '10.14470']);
    Config::set('database.connections.metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('metaworks');

    Schema::connection('metaworks')->create('resource', function (Blueprint $table): void {
        $table->id();
        $table->string('identifier')->nullable();
    });

    $this->service = new DoiImportEligibilityService(
        new LegacyResourceLookupService,
        app(DoiSuggestionService::class),
    );
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

it('allows configured DataCite production prefixes without a legacy row', function (): void {
    expect($this->service->canImport('10.14470/RV968923'))->toBeTrue();
});

it('allows DOI resolver URLs with configured prefixes', function (): void {
    expect($this->service->canImport('https://doi.org/10.14470/RV968923'))->toBeTrue();
});

it('falls back to SUMARIOPMD legacy lookup for unconfigured prefixes', function (): void {
    DB::connection('metaworks')->table('resource')->insert([
        'identifier' => '10.9999/legacy.only',
    ]);

    expect($this->service->canImport('10.9999/legacy.only'))->toBeTrue();
});

it('rejects unconfigured prefixes without a legacy row', function (): void {
    expect($this->service->canImport('10.9999/missing'))->toBeFalse();
});

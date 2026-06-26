<?php

declare(strict_types=1);

use App\Models\OldDataset;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
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

    Schema::connection('metaworks')->create('coverage', function (Blueprint $table): void {
        $table->id();
        $table->float('minlat')->nullable();
        $table->float('maxlat')->nullable();
        $table->float('minlon')->nullable();
        $table->float('maxlon')->nullable();
        $table->text('wkt')->nullable();
        $table->string('start')->nullable();
        $table->string('end')->nullable();
        $table->string('dateformat')->nullable();
        $table->text('description')->nullable();
        $table->unsignedBigInteger('resource_id');
    });

    DB::connection('metaworks')->table('resource')->insert([
        'id' => 1,
        'identifier' => '10.5880/legacy.coverage.001',
    ]);
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('coverage');
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

it('imports legacy coverage wkt coordinate chains as lines', function (): void {
    DB::connection('metaworks')->table('coverage')->insert([
        'resource_id' => 1,
        'minlat' => 49.2,
        'maxlat' => 49.4,
        'minlon' => 8.1,
        'maxlon' => 8.3,
        'wkt' => '8.1 49.2 8.3 49.4',
        'start' => '2024-01-01',
        'end' => '2024-01-31',
        'dateformat' => 'Y-m-d',
        'description' => 'Legacy profile line',
    ]);

    $coverages = OldDataset::findOrFail(1)->getCoverages();

    expect($coverages)->toHaveCount(1)
        ->and($coverages[0])->toMatchArray([
            'type' => 'line',
            'latMin' => '',
            'latMax' => '',
            'lonMin' => '',
            'lonMax' => '',
            'description' => 'Legacy profile line',
            'polygonPoints' => [
                ['lat' => 49.2, 'lon' => 8.1],
                ['lat' => 49.4, 'lon' => 8.3],
            ],
        ]);
});

it('falls back to legacy boxes when wkt cannot be parsed as a line', function (): void {
    DB::connection('metaworks')->table('coverage')->insert([
        'resource_id' => 1,
        'minlat' => 49.2,
        'maxlat' => 49.4,
        'minlon' => 8.1,
        'maxlon' => 8.3,
        'wkt' => '13.O57855 49.2 8.3 49.4',
        'description' => 'Fallback box',
    ]);

    $coverages = OldDataset::findOrFail(1)->getCoverages();

    expect($coverages)->toHaveCount(1)
        ->and($coverages[0])->toMatchArray([
            'type' => 'box',
            'latMin' => '49.200000',
            'latMax' => '49.400000',
            'lonMin' => '8.100000',
            'lonMax' => '8.300000',
            'description' => 'Fallback box',
        ])
        ->and($coverages[0])->not->toHaveKey('polygonPoints');
});

it('keeps point coverage behaviour when no wkt exists', function (): void {
    DB::connection('metaworks')->table('coverage')->insert([
        'resource_id' => 1,
        'minlat' => 49.2,
        'maxlat' => 49.2,
        'minlon' => 8.1,
        'maxlon' => 8.1,
        'wkt' => null,
        'description' => 'Legacy point',
    ]);

    $coverages = OldDataset::findOrFail(1)->getCoverages();

    expect($coverages)->toHaveCount(1)
        ->and($coverages[0])->toMatchArray([
            'type' => 'point',
            'latMin' => '49.200000',
            'latMax' => '',
            'lonMin' => '8.100000',
            'lonMax' => '',
        ]);
});

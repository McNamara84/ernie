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

it('keeps a missing legacy coverage timezone empty', function (): void {
    DB::connection('metaworks')->table('coverage')->insert([
        'resource_id' => 1,
        'start' => '2026-08-25 14:37:00',
        'end' => '2026-08-27 17:37:42',
        'dateformat' => 'Y-m-d H:i:s',
    ]);

    expect(OldDataset::findOrFail(1)->getCoverages()[0])->toMatchArray([
        'startDate' => '2026-08-25',
        'endDate' => '2026-08-27',
        'startTime' => '14:37',
        'endTime' => '17:37:42',
        'timezone' => '',
    ]);
});

it('preserves a legacy coverage timezone offset', function (): void {
    DB::connection('metaworks')->table('coverage')->insert([
        'resource_id' => 1,
        'start' => '2026-08-25T14:37:00+09:00',
        'end' => '2026-08-27T17:37:00+09:00',
        'dateformat' => 'Y-m-d\\TH:i:sT',
    ]);

    expect(OldDataset::findOrFail(1)->getCoverages()[0])->toMatchArray([
        'startDate' => '2026-08-25',
        'endDate' => '2026-08-27',
        'startTime' => '14:37',
        'endTime' => '17:37',
        'timezone' => '+09:00',
    ]);
});

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

    Schema::connection('metaworks')->create('date', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('resource_id');
        $table->string('datetype');
        $table->string('start')->nullable();
        $table->string('end')->nullable();
    });

    DB::connection('metaworks')->table('resource')->insert([
        'id' => 1,
        'identifier' => '10.5880/legacy.date.001',
    ]);
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('date');
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

it('marks legacy collected dates with start and end as periods', function (): void {
    DB::connection('metaworks')->table('date')->insert([
        'resource_id' => 1,
        'datetype' => 'Collected',
        'start' => '2024-01-01',
        'end' => '2024-12-31',
    ]);

    $dates = OldDataset::findOrFail(1)->getResourceDates();

    expect($dates)->toHaveCount(1)
        ->and($dates[0])->toMatchArray([
            'dateType' => 'collected',
            'dateMode' => 'range',
            'startDate' => '2024-01-01',
            'endDate' => '2024-12-31',
        ]);
});

it('clears end dates for unsupported legacy date types in single-date mode', function (): void {
    DB::connection('metaworks')->table('date')->insert([
        'resource_id' => 1,
        'datetype' => 'Available',
        'start' => '2024-01-01',
        'end' => '2024-12-31',
    ]);

    $dates = OldDataset::findOrFail(1)->getResourceDates();

    expect($dates)->toHaveCount(1)
        ->and($dates[0])->toMatchArray([
            'dateType' => 'available',
            'dateMode' => 'single',
            'startDate' => '2024-01-01',
            'endDate' => '',
        ]);
});

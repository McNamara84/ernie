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

    Schema::connection('metaworks')->create('description', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('resource_id');
        $table->string('descriptiontype');
        $table->text('description');
    });

    DB::connection('metaworks')->table('resource')->insert([
        'id' => 1,
        'identifier' => '10.5880/legacy.description.001',
    ]);
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('description');
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

it('keeps legacy description languages unassigned and preserves source order', function (): void {
    DB::connection('metaworks')->table('description')->insert([
        [
            'id' => 9,
            'resource_id' => 1,
            'descriptiontype' => 'Abstract',
            'description' => 'English abstract stored second.',
        ],
        [
            'id' => 3,
            'resource_id' => 1,
            'descriptiontype' => 'Abstract',
            'description' => 'Deutscher Abstract, zuerst gespeichert.',
        ],
    ]);

    $descriptions = OldDataset::findOrFail(1)->getDescriptions();

    expect($descriptions)->toBe([
        [
            'type' => 'Abstract',
            'description' => 'Deutscher Abstract, zuerst gespeichert.',
            'language' => null,
        ],
        [
            'type' => 'Abstract',
            'description' => 'English abstract stored second.',
            'language' => null,
        ],
    ]);
});

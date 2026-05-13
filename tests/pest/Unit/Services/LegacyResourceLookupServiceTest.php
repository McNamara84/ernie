<?php

declare(strict_types=1);

use App\Services\LegacyResourceLookupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Config::set('database.connections.metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('metaworks');

    Schema::connection('metaworks')->dropIfExists('resource');
    Schema::connection('metaworks')->create('resource', function (Blueprint $table): void {
        $table->id();
        $table->string('identifier')->nullable();
    });

    $this->service = new LegacyResourceLookupService;
});

afterEach(function () {
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

describe('LegacyResourceLookupService', function () {
    it('returns true when the DOI exists in the legacy resource table', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'identifier' => '10.5880/gfz.ojsj.2026.001',
        ]);

        expect($this->service->existsByDoi('10.5880/gfz.ojsj.2026.001'))->toBeTrue();
    });

    it('returns false when the DOI does not exist in the legacy resource table', function () {
        expect($this->service->existsByDoi('10.5880/gfz.ojsj.2026.999'))->toBeFalse();
    });
});
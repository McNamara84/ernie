<?php

declare(strict_types=1);

use App\Http\Controllers\OldDatasetController;
use App\Models\OldDataset;
use App\Services\LegacyKeywordService;
use App\Services\OldDatasetEditorLoader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
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
        $table->text('keywords')->nullable();
    });
    Schema::connection('metaworks')->create('thesauruskeyword', function (Blueprint $table): void {
        $table->unsignedBigInteger('resource_id');
        $table->string('keyword');
        $table->string('thesaurus');
    });
    Schema::connection('metaworks')->create('thesaurusvalue', function (Blueprint $table): void {
        $table->string('keyword');
        $table->string('thesaurus');
        $table->string('uri')->nullable();
        $table->text('description')->nullable();
    });

    $this->resourceId = DB::connection('metaworks')->table('resource')->insertGetId([
        'identifier' => '10.5880/GFZ.LKUT.2026.004',
        'keywords' => 'GNSS, , Crustal deformation',
    ]);
    DB::connection('metaworks')->table('thesauruskeyword')->insert([
        'resource_id' => $this->resourceId,
        'keyword' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
        'thesaurus' => 'NASA/GCMD Earth Science Keywords',
    ]);
    DB::connection('metaworks')->table('thesaurusvalue')->insert([
        'keyword' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
        'thesaurus' => 'NASA/GCMD Earth Science Keywords',
        'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/11111111-1111-4111-8111-111111111111',
        'description' => null,
    ]);
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('thesaurusvalue');
    Schema::connection('metaworks')->dropIfExists('thesauruskeyword');
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

it('uses the shared keyword service in the old dataset editor loader', function (): void {
    $loader = new OldDatasetEditorLoader(new LegacyKeywordService);
    $reflection = new ReflectionClass($loader);
    $loadControlledKeywords = $reflection->getMethod('loadControlledKeywords');
    $loadFreeKeywords = $reflection->getMethod('loadFreeKeywords');
    $dataset = OldDataset::findOrFail($this->resourceId);

    $controlledKeywords = $loadControlledKeywords->invoke($loader, $this->resourceId);

    expect($controlledKeywords)->toHaveCount(1)
        ->and($controlledKeywords[0])->toMatchArray([
            'path' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            'scheme' => 'Science Keywords',
            'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/11111111-1111-4111-8111-111111111111',
        ])
        ->and($loadFreeKeywords->invoke($loader, $dataset))->toBe([
            'GNSS',
            'Crustal deformation',
        ])
        ->and($loadControlledKeywords->invoke($loader, 999999))->toBe([]);
});

it('uses the shared keyword service in both legacy keyword endpoints', function (): void {
    $controller = new OldDatasetController(new LegacyKeywordService);
    $request = Request::create('/old-datasets/keywords', 'GET');

    $controlledResponse = $controller->getControlledKeywords($request, $this->resourceId);
    $freeResponse = $controller->getFreeKeywords($request, $this->resourceId);

    expect($controlledResponse->getStatusCode())->toBe(200)
        ->and($controlledResponse->getData(true)['keywords'])->toHaveCount(1)
        ->and($controlledResponse->getData(true)['keywords'][0])->toMatchArray([
            'path' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            'scheme' => 'Science Keywords',
        ])
        ->and($freeResponse->getStatusCode())->toBe(200)
        ->and($freeResponse->getData(true))->toBe([
            'keywords' => ['GNSS', 'Crustal deformation'],
        ]);
});

it('keeps both legacy keyword endpoints at 404 for missing resources', function (): void {
    $controller = new OldDatasetController(new LegacyKeywordService);
    $request = Request::create('/old-datasets/keywords', 'GET');

    expect($controller->getControlledKeywords($request, 999999)->getStatusCode())->toBe(404)
        ->and($controller->getFreeKeywords($request, 999999)->getStatusCode())->toBe(404);
});

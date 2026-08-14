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
        $table->text('keywords')->nullable();
    });
    Schema::connection('metaworks')->create('relatedidentifier', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('resource_id');
        $table->string('identifier')->nullable();
        $table->string('identifiertype')->nullable();
        $table->string('relationtype')->nullable();
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

    $this->service = new LegacyResourceLookupService;
});

afterEach(function () {
    Schema::connection('metaworks')->dropIfExists('thesaurusvalue');
    Schema::connection('metaworks')->dropIfExists('thesauruskeyword');
    Schema::connection('metaworks')->dropIfExists('relatedidentifier');
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
    it('matches DOI identifiers case-insensitively', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'identifier' => '10.14470/RV968923',
        ]);

        expect($this->service->existsByDoi('10.14470/rv968923'))->toBeTrue();
    });

    it('returns legacy related identifiers case-insensitively and in stable order', function () {
        $resourceId = DB::connection('metaworks')->table('resource')->insertGetId([
            'identifier' => '10.5880/CRC1211DB.88',
        ]);

        DB::connection('metaworks')->table('relatedidentifier')->insert([
            [
                'id' => 20,
                'resource_id' => $resourceId,
                'identifier' => '10.5880/CRC1211DB.89',
                'identifiertype' => 'DOI',
                'relationtype' => 'IsSupplementedBy',
            ],
            [
                'id' => 10,
                'resource_id' => $resourceId,
                'identifier' => '10.5880/CRC1211DB.86',
                'identifiertype' => 'DOI',
                'relationtype' => 'IsSupplementedBy',
            ],
        ]);

        expect($this->service->relatedIdentifiersByDoi('10.5880/crc1211db.88'))->toBe([
            [
                'identifier' => '10.5880/CRC1211DB.86',
                'identifierType' => 'DOI',
                'relationType' => 'IsSupplementedBy',
                'position' => 0,
            ],
            [
                'identifier' => '10.5880/CRC1211DB.89',
                'identifierType' => 'DOI',
                'relationType' => 'IsSupplementedBy',
                'position' => 1,
            ],
        ]);
    });

    it('returns an empty relation list when the DOI is absent from legacy storage', function () {
        expect($this->service->relatedIdentifiersByDoi('10.5880/missing'))->toBe([]);
    });

    it('returns related identifiers and both keyword types as import metadata', function () {
        $resourceId = DB::connection('metaworks')->table('resource')->insertGetId([
            'identifier' => '10.5880/GFZ.LKUT.2026.004',
            'keywords' => 'GNSS, Crustal deformation',
        ]);
        DB::connection('metaworks')->table('relatedidentifier')->insert([
            'resource_id' => $resourceId,
            'identifier' => '10.5880/GFZ.LKUT.2026.003',
            'identifiertype' => 'DOI',
            'relationtype' => 'IsNewVersionOf',
        ]);
        DB::connection('metaworks')->table('thesauruskeyword')->insert([
            'resource_id' => $resourceId,
            'keyword' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
        ]);
        DB::connection('metaworks')->table('thesaurusvalue')->insert([
            'keyword' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
            'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/11111111-1111-4111-8111-111111111111',
            'description' => null,
        ]);

        expect($this->service->importMetadataByDoi('10.5880/gfz.lkut.2026.004'))->toBe([
            'relatedIdentifiers' => [[
                'identifier' => '10.5880/GFZ.LKUT.2026.003',
                'identifierType' => 'DOI',
                'relationType' => 'IsNewVersionOf',
                'position' => 0,
            ]],
            'subjects' => [
                [
                    'subject' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS',
                    'subjectScheme' => 'Science Keywords',
                    'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/11111111-1111-4111-8111-111111111111',
                    'lang' => 'en',
                    'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                ],
                ['subject' => 'GNSS'],
                ['subject' => 'Crustal deformation'],
            ],
        ]);
    });

    it('returns empty import metadata when the DOI is absent from legacy storage', function () {
        expect($this->service->importMetadataByDoi('10.5880/missing'))->toBe([
            'relatedIdentifiers' => [],
            'subjects' => [],
        ]);
    });
});

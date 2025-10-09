<?php

use App\Models\OldDataset;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\getJson;

describe('OldDatasetController - Controlled Keywords', function () {
    beforeEach(function () {
        // Mock the metaworks database connection
        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturnSelf();
    });

    it('returns controlled keywords for a dataset', function () {
        // Mock OldDataset::find
        $mockDataset = Mockery::mock(OldDataset::class);
        OldDataset::shouldReceive('find')
            ->with(3)
            ->once()
            ->andReturn($mockDataset);

        // Mock the database query
        $mockBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
        
        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturnSelf();
        
        DB::shouldReceive('table')
            ->with('thesauruskeyword as tk')
            ->andReturn($mockBuilder);
        
        $mockBuilder->shouldReceive('join')
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('where')
            ->with('tk.resource_id', 3)
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('whereIn')
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('select')
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('get')
            ->once()
            ->andReturn(collect([
                (object) [
                    'keyword' => 'EARTH SCIENCE > SOLID EARTH > ROCKS/MINERALS/CRYSTALS > METAMORPHIC ROCKS > METAMORPHIC ROCK FORMATION',
                    'thesaurus' => 'NASA/GCMD Earth Science Keywords',
                    'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/a956d045-3b12-441c-8a18-fac7d33b2b4e',
                    'description' => null,
                ],
            ]));

        // Make request
        $response = getJson('/old-datasets/3/controlled-keywords');

        // Assert response
        $response->assertOk();
        $response->assertJsonStructure([
            'keywords' => [
                '*' => [
                    'id',
                    'text',
                    'vocabulary',
                    'path',
                    'uuid',
                    'description',
                ],
            ],
        ]);

        $keywords = $response->json('keywords');
        expect($keywords)->toHaveCount(1);
        expect($keywords[0])->toBe([
            'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/a956d045-3b12-441c-8a18-fac7d33b2b4e',
            'text' => 'EARTH SCIENCE > SOLID EARTH > ROCKS/MINERALS/CRYSTALS > METAMORPHIC ROCKS > METAMORPHIC ROCK FORMATION',
            'vocabulary' => 'gcmd-science-keywords',
            'path' => 'EARTH SCIENCE > SOLID EARTH > ROCKS/MINERALS/CRYSTALS > METAMORPHIC ROCKS > METAMORPHIC ROCK FORMATION',
            'uuid' => 'a956d045-3b12-441c-8a18-fac7d33b2b4e',
            'description' => null,
        ]);
    });

    it('returns empty array when dataset has no controlled keywords', function () {
        // Mock OldDataset::find
        $mockDataset = Mockery::mock(OldDataset::class);
        OldDataset::shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($mockDataset);

        // Mock the database query
        $mockBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
        
        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturnSelf();
        
        DB::shouldReceive('table')
            ->with('thesauruskeyword as tk')
            ->andReturn($mockBuilder);
        
        $mockBuilder->shouldReceive('join')
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('where')
            ->with('tk.resource_id', 1)
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('whereIn')
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('select')
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('get')
            ->once()
            ->andReturn(collect([]));

        // Make request
        $response = getJson('/old-datasets/1/controlled-keywords');

        // Assert response
        $response->assertOk();
        $response->assertJson([
            'keywords' => [],
        ]);
    });

    it('filters out non-GCMD keywords', function () {
        // Mock OldDataset::find
        $mockDataset = Mockery::mock(OldDataset::class);
        OldDataset::shouldReceive('find')
            ->with(3)
            ->once()
            ->andReturn($mockDataset);

        // Mock the database query
        $mockBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
        
        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturnSelf();
        
        DB::shouldReceive('table')
            ->with('thesauruskeyword as tk')
            ->andReturn($mockBuilder);
        
        $mockBuilder->shouldReceive('join')
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('where')
            ->with('tk.resource_id', 3)
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('whereIn')
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('select')
            ->once()
            ->andReturnSelf();
        
        $mockBuilder->shouldReceive('get')
            ->once()
            ->andReturn(collect([
                (object) [
                    'keyword' => 'EARTH SCIENCE',
                    'thesaurus' => 'NASA/GCMD Earth Science Keywords',
                    'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/e9f67a66-e9fc-435c-b720-ae32a2c3d8f5',
                    'description' => null,
                ],
                (object) [
                    'keyword' => 'Aircraft',
                    'thesaurus' => 'GCMD Platforms',
                    'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/227d9c3d-f631-402d-84ed-b8c5a562fc27',
                    'description' => null,
                ],
            ]));

        // Make request
        $response = getJson('/old-datasets/3/controlled-keywords');

        // Assert response
        $response->assertOk();
        $keywords = $response->json('keywords');
        expect($keywords)->toHaveCount(2);
        expect($keywords[0]['vocabulary'])->toBe('gcmd-science-keywords');
        expect($keywords[1]['vocabulary'])->toBe('gcmd-platforms');
    });

    it('returns 404 when dataset not found', function () {
        // Mock OldDataset::find returning null
        OldDataset::shouldReceive('find')
            ->with(999)
            ->once()
            ->andReturn(null);

        // Make request
        $response = getJson('/old-datasets/999/controlled-keywords');

        // Assert response
        $response->assertNotFound();
        $response->assertJson([
            'error' => 'Dataset not found',
        ]);
    });
});

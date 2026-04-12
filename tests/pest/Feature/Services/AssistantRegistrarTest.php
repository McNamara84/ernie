<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Person;
use App\Models\Resource;
use App\Services\Assistance\AssistantContract;
use App\Services\Assistance\AssistantManifest;
use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Support\Facades\DB;

covers(AssistantRegistrar::class);

// =========================================================================
// discoverModules()
// =========================================================================

describe('discoverModules', function () {
    it('discovers modules from modules/assistants directory', function () {
        $registrar = new AssistantRegistrar();
        $registrar->discoverModules(base_path('modules/assistants'));

        $all = $registrar->getAll();
        expect($all)->toBeArray()
            ->and(count($all))->toBeGreaterThanOrEqual(3);
    });

    it('returns empty when directory does not exist', function () {
        $registrar = new AssistantRegistrar();
        $registrar->discoverModules('/non/existent/path');

        expect($registrar->getAll())->toBeEmpty();
    });

    it('sorts modules by sortOrder', function () {
        $registrar = new AssistantRegistrar();
        $registrar->discoverModules(base_path('modules/assistants'));

        $all = array_values($registrar->getAll());
        $sortOrders = array_map(fn (AssistantContract $a) => $a->getManifest()->sortOrder, $all);

        $sorted = $sortOrders;
        sort($sorted);
        expect($sortOrders)->toBe($sorted);
    });
});

// =========================================================================
// registerFromPaths()
// =========================================================================

describe('registerFromPaths', function () {
    it('registers assistants from manifest paths', function () {
        $registrar = new AssistantRegistrar();
        $paths = glob(base_path('modules/assistants/*/manifest.json'));
        $registrar->registerFromPaths($paths !== false ? $paths : []);

        expect(count($registrar->getAll()))->toBeGreaterThanOrEqual(3);
    });

    it('skips invalid manifest paths gracefully', function () {
        $registrar = new AssistantRegistrar();
        $registrar->registerFromPaths(['/non/existent/manifest.json']);

        expect($registrar->getAll())->toBeEmpty();
    });
});

// =========================================================================
// get() / has()
// =========================================================================

describe('get and has', function () {
    it('returns assistant by ID', function () {
        $registrar = new AssistantRegistrar();
        $registrar->discoverModules(base_path('modules/assistants'));

        $assistant = $registrar->get('relation-suggestion');
        expect($assistant)->not->toBeNull()
            ->and($assistant->getId())->toBe('relation-suggestion');
    });

    it('returns null for unknown ID', function () {
        $registrar = new AssistantRegistrar();
        $registrar->discoverModules(base_path('modules/assistants'));

        expect($registrar->get('non-existent'))->toBeNull();
    });

    it('has() returns true for registered assistant', function () {
        $registrar = new AssistantRegistrar();
        $registrar->discoverModules(base_path('modules/assistants'));

        expect($registrar->has('relation-suggestion'))->toBeTrue();
    });

    it('has() returns false for unregistered assistant', function () {
        $registrar = new AssistantRegistrar();
        $registrar->discoverModules(base_path('modules/assistants'));

        expect($registrar->has('non-existent'))->toBeFalse();
    });
});

// =========================================================================
// register() (manual registration)
// =========================================================================

describe('register', function () {
    it('manually registers an assistant', function () {
        $registrar = new AssistantRegistrar();

        $mockAssistant = Mockery::mock(AssistantContract::class);
        $mockAssistant->shouldReceive('getId')->andReturn('mock-assistant');

        $registrar->register($mockAssistant);

        expect($registrar->has('mock-assistant'))->toBeTrue()
            ->and($registrar->get('mock-assistant'))->toBe($mockAssistant);
    });
});

// =========================================================================
// totalPendingCount()
// =========================================================================

describe('totalPendingCount', function () {
    it('sums pending counts across all suggestion tables', function () {
        $resource = Resource::factory()->create();
        $registrar = new AssistantRegistrar();

        // Register a dummy assistant so the list is not empty
        $assistant = Mockery::mock(AssistantContract::class);
        $assistant->shouldReceive('getId')->andReturn('test');
        $registrar->register($assistant);

        // Insert rows in each legacy table (seed FK targets first)
        $identifierType = DB::table('identifier_types')->insertGetId([
            'name' => 'DOI',
            'slug' => 'doi',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $relationType = DB::table('relation_types')->insertGetId([
            'name' => 'IsSupplementTo',
            'slug' => 'is-supplement-to',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $person = Person::factory()->create();

        DB::table('suggested_relations')->insert([
            'resource_id' => $resource->id,
            'identifier' => '10.1234/test',
            'identifier_type_id' => $identifierType,
            'relation_type_id' => $relationType,
            'source' => 'scholexplorer',
            'discovered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('suggested_orcids')->insert([
            'resource_id' => $resource->id,
            'person_id' => $person->id,
            'suggested_orcid' => '0000-0001-2345-6789',
            'similarity_score' => 0.9,
            'source_context' => 'creator',
            'discovered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('suggested_rors')->insert([
            'resource_id' => $resource->id,
            'entity_type' => 'affiliation',
            'entity_id' => 1,
            'entity_name' => 'GFZ Potsdam',
            'suggested_ror_id' => 'https://ror.org/04t3en479',
            'suggested_name' => 'GFZ German Research Centre for Geosciences',
            'similarity_score' => 0.85,
            'discovered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert row in the generic table
        AssistantSuggestion::create([
            'assistant_id' => 'test',
            'resource_id' => $resource->id,
            'target_type' => 'right',
            'target_id' => 1,
            'suggested_value' => 'MIT',
            'suggested_label' => 'MIT License',
            'discovered_at' => now(),
        ]);

        expect($registrar->totalPendingCount())->toBe(4);
    });

    it('returns zero when no assistants registered', function () {
        $registrar = new AssistantRegistrar();
        expect($registrar->totalPendingCount())->toBe(0);
    });
});

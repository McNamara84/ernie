<?php

declare(strict_types=1);

use App\Services\Assistance\AssistantContract;
use App\Services\Assistance\AssistantManifest;
use App\Services\Assistance\AssistantRegistrar;

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
    it('sums countPending across all registered assistants', function () {
        $registrar = new AssistantRegistrar();

        $assistantA = Mockery::mock(AssistantContract::class);
        $assistantA->shouldReceive('getId')->andReturn('assistant-a');
        $assistantA->shouldReceive('countPending')->once()->andReturn(5);

        $assistantB = Mockery::mock(AssistantContract::class);
        $assistantB->shouldReceive('getId')->andReturn('assistant-b');
        $assistantB->shouldReceive('countPending')->once()->andReturn(3);

        $registrar->register($assistantA);
        $registrar->register($assistantB);

        expect($registrar->totalPendingCount())->toBe(8);
    });

    it('excludes unregistered assistants from the count', function () {
        $registrar = new AssistantRegistrar();

        // Only register one assistant — the other should not be counted
        $registered = Mockery::mock(AssistantContract::class);
        $registered->shouldReceive('getId')->andReturn('registered');
        $registered->shouldReceive('countPending')->once()->andReturn(2);

        $registrar->register($registered);

        expect($registrar->totalPendingCount())->toBe(2);
    });

    it('returns zero when no assistants registered', function () {
        $registrar = new AssistantRegistrar();
        expect($registrar->totalPendingCount())->toBe(0);
    });
});

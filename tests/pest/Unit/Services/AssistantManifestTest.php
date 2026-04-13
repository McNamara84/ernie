<?php

declare(strict_types=1);

use App\Services\Assistance\AssistantManifest;

covers(AssistantManifest::class);

// =========================================================================
// fromFile()
// =========================================================================

describe('fromFile', function () {
    it('parses a valid manifest.json', function () {
        $manifest = AssistantManifest::fromFile(
            base_path('modules/assistants/RelationSuggestion/manifest.json'),
        );

        expect($manifest->id)->toBe('relation-suggestion')
            ->and($manifest->name)->toBe('Relation Suggestions')
            ->and($manifest->assistantClass)->toBe('Modules\\Assistants\\RelationSuggestion\\Assistant')
            ->and($manifest->routePrefix)->toBe('relations')
            ->and($manifest->lockKey)->toBe('relation_discovery_running')
            ->and($manifest->cacheKeyPrefix)->toBe('relation_discovery')
            ->and($manifest->sortOrder)->toBe(10);
    });

    it('throws for missing file', function () {
        AssistantManifest::fromFile('/non/existent/manifest.json');
    })->throws(InvalidArgumentException::class, 'Manifest file not found');

    it('throws for invalid JSON', function () {
        $path = tempnam(sys_get_temp_dir(), 'manifest_');
        file_put_contents($path, '{ invalid json }');

        try {
            AssistantManifest::fromFile($path);
        } finally {
            unlink($path);
        }
    })->throws(InvalidArgumentException::class, 'Invalid JSON in manifest file');

    it('provides default status labels', function () {
        $manifest = AssistantManifest::fromFile(
            base_path('modules/assistants/RelationSuggestion/manifest.json'),
        );

        expect($manifest->statusLabels)->toHaveKeys([
            'checking',
            'completed_with_results',
            'completed_empty',
            'failed',
            'already_running',
        ]);
    });

    it('provides default empty state', function () {
        $manifest = AssistantManifest::fromFile(
            base_path('modules/assistants/RelationSuggestion/manifest.json'),
        );

        expect($manifest->emptyState)->toHaveKeys(['title', 'description']);
    });
});

// =========================================================================
// toArray()
// =========================================================================

describe('toArray', function () {
    it('returns array without sensitive fields', function () {
        $manifest = AssistantManifest::fromFile(
            base_path('modules/assistants/RelationSuggestion/manifest.json'),
        );

        $array = $manifest->toArray();

        expect($array)->toHaveKeys([
            'id', 'name', 'description', 'icon', 'version',
            'routePrefix', 'sortOrder', 'statusLabels', 'emptyState',
        ]);

        // Should NOT expose sensitive backend-only fields
        expect($array)->not->toHaveKey('assistantClass')
            ->and($array)->not->toHaveKey('lockKey')
            ->and($array)->not->toHaveKey('cacheKeyPrefix');
    });
});

<?php

declare(strict_types=1);

use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\User;
use App\Services\Assistance\GenericTableAssistant;

covers(GenericTableAssistant::class);

/**
 * Concrete test implementation of GenericTableAssistant.
 */
class TestGenericAssistant extends GenericTableAssistant
{
    private int $discoverResult = 0;

    private ?Closure $discoverCallback = null;

    #[\Override]
    protected function getManifestPath(): string
    {
        return base_path('modules/assistants/RelationSuggestion/manifest.json');
    }

    #[\Override]
    protected function discover(Closure $onProgress): int
    {
        if ($this->discoverCallback !== null) {
            return ($this->discoverCallback)($onProgress);
        }

        return $this->discoverResult;
    }

    #[\Override]
    protected function applyAccepted(AssistantSuggestion $suggestion): array
    {
        return ['success' => true, 'message' => 'Applied test suggestion.'];
    }

    public function setDiscoverResult(int $result): void
    {
        $this->discoverResult = $result;
    }

    public function setDiscoverCallback(Closure $callback): void
    {
        $this->discoverCallback = $callback;
    }

    /**
     * Public proxy for testing the protected storeSuggestion().
     */
    public function storeSuggestion(
        int $resourceId,
        string $targetType,
        int $targetId,
        string $suggestedValue,
        string $suggestedLabel,
        ?float $similarityScore = null,
        ?array $metadata = null,
    ): bool {
        return parent::storeSuggestion($resourceId, $targetType, $targetId, $suggestedValue, $suggestedLabel, $similarityScore, $metadata);
    }
}

// =========================================================================
// storeSuggestion()
// =========================================================================

describe('storeSuggestion', function () {
    it('stores a new suggestion', function () {
        $resource = Resource::factory()->create();
        $assistant = new TestGenericAssistant();

        $stored = $assistant->storeSuggestion(
            resourceId: $resource->id,
            targetType: 'right',
            targetId: 1,
            suggestedValue: 'MIT',
            suggestedLabel: 'MIT License',
            similarityScore: 0.95,
        );

        expect($stored)->toBeTrue();
        expect(AssistantSuggestion::where('assistant_id', $assistant->getId())
            ->where('suggested_value', 'MIT')
            ->exists())->toBeTrue();
    });

    it('skips duplicate suggestions', function () {
        $resource = Resource::factory()->create();
        $assistant = new TestGenericAssistant();

        $first = $assistant->storeSuggestion(
            resourceId: $resource->id,
            targetType: 'right',
            targetId: 1,
            suggestedValue: 'MIT',
            suggestedLabel: 'MIT License',
        );

        $second = $assistant->storeSuggestion(
            resourceId: $resource->id,
            targetType: 'right',
            targetId: 1,
            suggestedValue: 'MIT',
            suggestedLabel: 'MIT License (updated)',
        );

        expect($first)->toBeTrue()
            ->and($second)->toBeFalse();

        // Only one row should exist
        expect(AssistantSuggestion::where('assistant_id', $assistant->getId())
            ->where('target_type', 'right')
            ->where('target_id', 1)
            ->where('suggested_value', 'MIT')
            ->count())->toBe(1);
    });

    it('does not update existing suggestion when duplicate is stored', function () {
        $resource = Resource::factory()->create();
        $assistant = new TestGenericAssistant();

        $assistant->storeSuggestion(
            resourceId: $resource->id,
            targetType: 'right',
            targetId: 1,
            suggestedValue: 'MIT',
            suggestedLabel: 'MIT License',
            similarityScore: 0.80,
        );

        // Attempt to store again with different label and score
        $assistant->storeSuggestion(
            resourceId: $resource->id,
            targetType: 'right',
            targetId: 1,
            suggestedValue: 'MIT',
            suggestedLabel: 'Updated Label',
            similarityScore: 0.99,
        );

        $suggestion = AssistantSuggestion::where('assistant_id', $assistant->getId())
            ->where('suggested_value', 'MIT')
            ->first();

        // Original values should be preserved (firstOrCreate does not update)
        expect($suggestion->suggested_label)->toBe('MIT License')
            ->and($suggestion->similarity_score)->toBe(0.80);
    });

    it('skips previously dismissed suggestions', function () {
        $resource = Resource::factory()->create();
        $user = User::factory()->create();
        $assistant = new TestGenericAssistant();

        // Pre-dismiss the suggestion
        AssistantDismissed::create([
            'assistant_id' => $assistant->getId(),
            'target_type' => 'right',
            'target_id' => 1,
            'dismissed_value' => 'MIT',
            'dismissed_by' => $user->id,
        ]);

        $stored = $assistant->storeSuggestion(
            resourceId: $resource->id,
            targetType: 'right',
            targetId: 1,
            suggestedValue: 'MIT',
            suggestedLabel: 'MIT License',
        );

        expect($stored)->toBeFalse();
        expect(AssistantSuggestion::where('assistant_id', $assistant->getId())
            ->where('suggested_value', 'MIT')
            ->exists())->toBeFalse();
    });

    it('stores with metadata', function () {
        $resource = Resource::factory()->create();
        $assistant = new TestGenericAssistant();

        $assistant->storeSuggestion(
            resourceId: $resource->id,
            targetType: 'right',
            targetId: 1,
            suggestedValue: 'MIT',
            suggestedLabel: 'MIT License',
            metadata: ['source' => 'spdx', 'url' => 'https://spdx.org/licenses/MIT.html'],
        );

        $suggestion = AssistantSuggestion::where('assistant_id', $assistant->getId())->first();
        expect($suggestion->metadata)->toBeArray()
            ->and($suggestion->metadata['source'])->toBe('spdx');
    });
});

// =========================================================================
// countPending()
// =========================================================================

describe('countPending', function () {
    it('counts suggestions for this assistant only', function () {
        $resource = Resource::factory()->create();
        $assistant = new TestGenericAssistant();

        AssistantSuggestion::create([
            'assistant_id' => $assistant->getId(),
            'resource_id' => $resource->id,
            'target_type' => 'right',
            'target_id' => 1,
            'suggested_value' => 'MIT',
            'suggested_label' => 'MIT License',
            'discovered_at' => now(),
        ]);

        AssistantSuggestion::create([
            'assistant_id' => 'other-assistant',
            'resource_id' => $resource->id,
            'target_type' => 'right',
            'target_id' => 2,
            'suggested_value' => 'Apache-2.0',
            'suggested_label' => 'Apache 2.0',
            'discovered_at' => now(),
        ]);

        expect($assistant->countPending())->toBe(1);
    });
});

// =========================================================================
// loadSuggestions()
// =========================================================================

describe('loadSuggestions', function () {
    it('returns paginated and transformed suggestions', function () {
        $resource = Resource::factory()->create();
        $assistant = new TestGenericAssistant();

        AssistantSuggestion::create([
            'assistant_id' => $assistant->getId(),
            'resource_id' => $resource->id,
            'target_type' => 'right',
            'target_id' => 1,
            'suggested_value' => 'MIT',
            'suggested_label' => 'MIT License',
            'similarity_score' => 0.95,
            'discovered_at' => now(),
        ]);

        $paginator = $assistant->loadSuggestions(25);

        expect($paginator->total())->toBe(1);

        $item = $paginator->items()[0];
        expect($item)->toBeArray()
            ->and($item['suggested_value'])->toBe('MIT')
            ->and($item['suggested_label'])->toBe('MIT License')
            ->and($item['similarity_score'])->toBe(0.95)
            ->and($item['resource_doi'])->toBe($resource->doi)
            ->and($item)->toHaveKeys(['id', 'assistant_id', 'resource_id', 'target_type', 'target_id', 'discovered_at']);
    });
});

// =========================================================================
// acceptSuggestion() / declineSuggestion()
// =========================================================================

describe('acceptSuggestion', function () {
    it('applies and deletes the accepted suggestion', function () {
        $resource = Resource::factory()->create();
        $assistant = new TestGenericAssistant();

        $suggestion = AssistantSuggestion::create([
            'assistant_id' => $assistant->getId(),
            'resource_id' => $resource->id,
            'target_type' => 'right',
            'target_id' => 1,
            'suggested_value' => 'MIT',
            'suggested_label' => 'MIT License',
            'discovered_at' => now(),
        ]);

        $result = $assistant->acceptSuggestion($suggestion->id);

        expect($result['success'])->toBeTrue();
        expect(AssistantSuggestion::find($suggestion->id))->toBeNull();
    });

    it('returns failure for non-existent suggestion', function () {
        $assistant = new TestGenericAssistant();
        $result = $assistant->acceptSuggestion(9999);

        expect($result['success'])->toBeFalse();
    });

    it('invalidates total pending count cache', function () {
        $resource = Resource::factory()->create();
        $assistant = new TestGenericAssistant();

        $cacheEnum = \App\Enums\CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT;
        $cacheKey = $cacheEnum->key();
        $tags = $cacheEnum->tags();
        Cache::tags($tags)->put($cacheKey, 5, now()->addHour());

        $suggestion = AssistantSuggestion::create([
            'assistant_id' => $assistant->getId(),
            'resource_id' => $resource->id,
            'target_type' => 'right',
            'target_id' => 1,
            'suggested_value' => 'MIT',
            'suggested_label' => 'MIT License',
            'discovered_at' => now(),
        ]);

        $assistant->acceptSuggestion($suggestion->id);

        expect(Cache::tags($tags)->has($cacheKey))->toBeFalse();
    });
});

describe('declineSuggestion', function () {
    it('creates dismissed record and deletes suggestion', function () {
        $resource = Resource::factory()->create();
        $user = User::factory()->create();
        $assistant = new TestGenericAssistant();

        $suggestion = AssistantSuggestion::create([
            'assistant_id' => $assistant->getId(),
            'resource_id' => $resource->id,
            'target_type' => 'right',
            'target_id' => 1,
            'suggested_value' => 'MIT',
            'suggested_label' => 'MIT License',
            'discovered_at' => now(),
        ]);

        $assistant->declineSuggestion($suggestion->id, $user, 'Not applicable');

        // Suggestion should be deleted
        expect(AssistantSuggestion::find($suggestion->id))->toBeNull();

        // Dismissed record should exist
        $dismissed = AssistantDismissed::where('assistant_id', $assistant->getId())
            ->where('dismissed_value', 'MIT')
            ->first();

        expect($dismissed)->not->toBeNull()
            ->and($dismissed->dismissed_by)->toBe($user->id)
            ->and($dismissed->reason)->toBe('Not applicable');
    });

    it('invalidates total pending count cache', function () {
        $resource = Resource::factory()->create();
        $user = User::factory()->create();
        $assistant = new TestGenericAssistant();

        $cacheEnum = \App\Enums\CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT;
        $cacheKey = $cacheEnum->key();
        $tags = $cacheEnum->tags();
        Cache::tags($tags)->put($cacheKey, 5, now()->addHour());

        $suggestion = AssistantSuggestion::create([
            'assistant_id' => $assistant->getId(),
            'resource_id' => $resource->id,
            'target_type' => 'right',
            'target_id' => 1,
            'suggested_value' => 'MIT',
            'suggested_label' => 'MIT License',
            'discovered_at' => now(),
        ]);

        $assistant->declineSuggestion($suggestion->id, $user, 'Not relevant');

        expect(Cache::tags($tags)->has($cacheKey))->toBeFalse();
    });
});

// =========================================================================
// runDiscovery()
// =========================================================================

describe('runDiscovery', function () {
    it('delegates to discover() method', function () {
        $assistant = new TestGenericAssistant();
        $assistant->setDiscoverResult(7);

        $progressMessages = [];
        $result = $assistant->runDiscovery(function (string $msg) use (&$progressMessages) {
            $progressMessages[] = $msg;
        });

        expect($result)->toBe(7);
    });
});

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AssistantSuggestion;
use Modules\Assistants\SizeFormatSuggestion\SizeFormatDataTransformer;
use Tests\TestCase;

class SizeFormatTraceabilityTest extends TestCase
{
    public function test_transformer_enriches_payload_and_preserves_all_legacy_fields(): void
    {
        $suggestion = new AssistantSuggestion();
        $suggestion->forceFill([
            'id' => 999,
            'assistant_id' => 5,
            'resource_id' => 42,
            'target_id' => 101,
            'target_type' => 'resource',
            'suggested_value' => '10 MB',
            'suggested_label' => 'Size Suggestion',
            'similarity_score' => 0.95,
            'metadata' => [
                'confidence' => 'high',
                'probe_method' => 'DIRECTORY_LISTING',
                'source_url' => 'https://dataservices.gfz-potsdam.de/test',
            ]
        ]);

        $transformer = new SizeFormatDataTransformer();
        $transformed = $transformer->transformItem($suggestion);

        // Assert contract safety fields requested by Daniel
        $this->assertEquals(5, $transformed['assistant_id']);
        $this->assertEquals(42, $transformed['resource_id']);
        $this->assertEquals(101, $transformed['target_id']);
        $this->assertEquals(0.95, $transformed['similarity_score']);
        $this->assertArrayHasKey('discovered_at', $transformed);

        // Assert Traceability extensions
        $this->assertEquals('Verified directly from the repository structural directory tree listing.', $transformed['explanation']);
        $this->assertEquals('Open GFZ Data Services Listing', $transformed['link_label']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AssistantSuggestion;
use Modules\Assistants\SizeFormatSuggestion\SizeFormatDataTransformer;
use Tests\TestCase;

class SizeFormatTraceabilityTest extends TestCase
{
    /**
     * Tests that the transformer fulfills the frontend shape contract while successfully
     * enriching the dataset with explanations and source label parameters.
     */
    public function test_transformer_enriches_payload_and_preserves_legacy_shape(): void
    {
        $suggestion = new AssistantSuggestion();
        $suggestion->id = 999;
        $suggestion->target_type = 'resource';
        $suggestion->suggested_value = '10 MB';
        $suggestion->suggested_label = 'Size Suggestion';
        $suggestion->metadata = [
            'confidence' => 'high',
            'probe_method' => 'DIRECTORY_LISTING',
            'source_url' => 'https://dataservices.gfz-potsdam.de/test',
        ];

        $transformer = new SizeFormatDataTransformer();
        $transformed = $transformer->transformItem($suggestion);

        // Assert Legacy UI shape contract remains intact
        $this->assertEquals('resource', $transformed['target_type']);
        $this->assertEquals('https://dataservices.gfz-potsdam.de/test', $transformed['metadata']['source_url']);
        $this->assertEquals('DIRECTORY_LISTING', $transformed['metadata']['probe_method']);
        $this->assertEquals('high', $transformed['metadata']['confidence']);

        // Assert Enriched Traceability data is present
        $this->assertArrayHasKey('explanation', $transformed);
        $this->assertArrayHasKey('link_label', $transformed);
        $this->assertArrayHasKey('technical_meta', $transformed);
        
        $this->assertEquals('Verified directly from the repository structural directory tree listing.', $transformed['explanation']);
        $this->assertEquals('Open GFZ Data Services Listing', $transformed['link_label']);
    }
}
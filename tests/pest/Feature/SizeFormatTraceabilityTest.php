<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AssistantSuggestion;
use Modules\assistants\SizeFormatSuggestion\SizeFormatDataTransformer;
use Tests\TestCase;

class SizeFormatTraceabilityTest extends TestCase
{
    public function test_it_groups_output_data_into_logical_visual_categories(): void
    {
        $suggestion = new AssistantSuggestion();
        $suggestion->forceFill([
            'id' => 123,
            'assistant_id' => 1,
            'resource_id' => 55,
            'target_id' => 88,
            'target_type' => 'resource',
            'suggested_value' => '500 KB',
            'suggested_label' => 'Format Suggestion',
            'similarity_score' => 0.80,
            'metadata' => [
                'confidence' => 'medium',
                'probe_method' => 'FILENAME_EXTENSION_FALLBACK',
                'source_url' => 'https://dataservices.gfz-potsdam.de/test-file',
            ]
        ]);

        $transformer = new SizeFormatDataTransformer();
        $transformed = $transformer->transformItem($suggestion);

        // Assert Group 1: Base Core fields
        $this->assertEquals(123, $transformed['id']);
        $this->assertEquals('500 KB', $transformed['suggested_value']);

        // Assert Group 2: Curation Support Group exists and is separated
        $this->assertArrayHasKey('curation_support', $transformed);
        $this->assertEquals('Open GFZ Data Services Listing', $transformed['curation_support']['link_label']);
        $this->assertStringContainsString('filename regex', $transformed['curation_support']['explanation']);

        // Assert Group 3: Technical Meta Group exists and is separated
        $this->assertArrayHasKey('technical_meta', $transformed);
        $this->assertEquals('FILENAME_EXTENSION_FALLBACK', $transformed['technical_meta']['Probing Route']);
    }
}
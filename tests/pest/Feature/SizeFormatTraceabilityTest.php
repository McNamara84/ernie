<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\assistants\SizeFormatSuggestion\SizeFormatConfidenceExplainer;
use Tests\TestCase;

class SizeFormatTraceabilityTest extends TestCase
{
    public function test_it_resolves_high_confidence_for_directory_listings(): void
    {
        $explainer = new SizeFormatConfidenceExplainer();
        $explanation = $explainer->resolve('high', 'DIRECTORY_LISTING');

        $this->assertEquals(
            'Verified directly from the repository structural directory tree listing.',
            $explanation
        );
    }

    public function test_it_resolves_medium_confidence_for_filename_fallbacks(): void
    {
        $explainer = new SizeFormatConfidenceExplainer();
        $explanation = $explainer->resolve('medium', 'FILENAME_EXTENSION_FALLBACK');

        $this->assertEquals(
            'Extracted based on filename regex mapping. Please verify manually.',
            $explanation
        );
    }

    public function test_it_resolves_low_confidence_for_unknown_or_incomplete_metadata(): void
    {
        $explainer = new SizeFormatConfidenceExplainer();
        $explanation = $explainer->resolve('low', 'UNKNOWN');

        $this->assertEquals(
            'Incomplete file metadata stream detected. Requires strict curation validation.',
            $explanation
        );
    }
}
<?php

declare(strict_types=1);

namespace Modules\assistants\SizeFormatSuggestion;

class SizeFormatConfidenceExplainer
{
    /**
     * Resolves a human-readable explanation of why a suggestion got its confidence level.
     * This directly addresses Task 1: "Understanding confidence levels".
     */
    public function resolve(string $confidence, string $probeMethod): string
    {
        if ($confidence === 'high' && $probeMethod === 'DIRECTORY_LISTING') {
            return 'Verified directly from the repository structural directory tree listing.';
        }

        if ($confidence === 'medium') {
            return $probeMethod === 'FILENAME_EXTENSION_FALLBACK'
                ? 'Extracted based on filename regex mapping. Please verify manually.'
                : 'Derived from partial server response metadata.';
        }

        return 'Incomplete file metadata stream detected. Requires strict curation validation.';
    }
}
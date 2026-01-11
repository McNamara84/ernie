<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;

/**
 * Service for DOI validation and suggestion.
 *
 * Handles checking for existing DOIs in the database and generating
 * suggestions for the next available DOI based on detected patterns.
 */
class DoiSuggestionService
{
    /**
     * Check if a DOI already exists in the database.
     *
     * @param  string  $doi  The DOI to check
     * @param  int|null  $excludeResourceId  Optional resource ID to exclude (for edit mode)
     */
    public function checkDoiExists(string $doi, ?int $excludeResourceId = null): bool
    {
        $normalizedDoi = $this->normalizeDoi($doi);

        $query = Resource::where('doi', $normalizedDoi);

        if ($excludeResourceId !== null) {
            $query->where('id', '!=', $excludeResourceId);
        }

        return $query->exists();
    }

    /**
     * Get the resource that has the given DOI.
     *
     * @param  string  $doi  The DOI to look up
     * @param  int|null  $excludeResourceId  Optional resource ID to exclude
     * @return array{id: int, title: string|null}|null
     */
    public function getResourceByDoi(string $doi, ?int $excludeResourceId = null): ?array
    {
        $normalizedDoi = $this->normalizeDoi($doi);

        $query = Resource::where('doi', $normalizedDoi)
            ->with(['titles' => function ($q) {
                $q->whereHas('titleType', function ($q2) {
                    $q2->where('slug', 'main-title');
                })->limit(1);
            }]);

        if ($excludeResourceId !== null) {
            $query->where('id', '!=', $excludeResourceId);
        }

        $resource = $query->first();

        if ($resource === null) {
            return null;
        }

        $mainTitle = $resource->titles->first()?->value;

        return [
            'id' => $resource->id,
            'title' => $mainTitle,
        ];
    }

    /**
     * Get the last assigned DOI globally (most recently created resource with a DOI).
     */
    public function getLastAssignedDoi(): ?string
    {
        $resource = Resource::whereNotNull('doi')
            ->orderByDesc('created_at')
            ->first(['doi']);

        return $resource?->doi;
    }

    /**
     * Suggest the next available DOI based on the input DOI pattern.
     *
     * Analyzes the input DOI to detect its pattern and suggests the next
     * sequential number while ensuring it doesn't already exist.
     *
     * @param  string  $inputDoi  The DOI to base the suggestion on
     */
    public function suggestNextDoi(string $inputDoi): ?string
    {
        $normalizedDoi = $this->normalizeDoi($inputDoi);

        // Extract prefix and suffix
        if (! preg_match('/^(10\.\d+)\/(.+)$/', $normalizedDoi, $matches)) {
            return null;
        }

        $prefix = $matches[1];
        $suffix = $matches[2];

        // Try to detect pattern and generate suggestion
        $suggestion = $this->generateSuggestionForSuffix($prefix, $suffix);

        if ($suggestion !== null) {
            return $suggestion;
        }

        // Fallback: Use current year with sequential number
        return $this->generateFallbackSuggestion($prefix, $suffix);
    }

    /**
     * Normalize a DOI by removing URL prefix and trimming whitespace.
     */
    public function normalizeDoi(string $doi): string
    {
        $doi = trim($doi);

        // Remove URL prefix if present
        if (preg_match('/^https?:\/\/(?:dx\.)?doi\.org\/(.+)$/i', $doi, $matches)) {
            $doi = $matches[1];
        }

        return $doi;
    }

    /**
     * Validate DOI format.
     */
    public function isValidDoiFormat(string $doi): bool
    {
        $normalizedDoi = $this->normalizeDoi($doi);

        // DOI format: 10.NNNN/suffix
        return (bool) preg_match('/^10\.\d{4,}(?:\.\d+)*\/\S+$/', $normalizedDoi);
    }

    /**
     * Generate a suggestion based on detected suffix pattern.
     */
    private function generateSuggestionForSuffix(string $prefix, string $suffix): ?string
    {
        // Pattern: [projekt].[jahr].[nummer]
        if (preg_match('/^([a-z0-9-]+)\.(\d{4})\.(\d+)$/i', $suffix, $matches)) {
            return $this->findNextAvailable(
                $prefix,
                fn (int $num) => sprintf('%s.%s.%03d', $matches[1], $matches[2], $num),
                (int) $matches[3]
            );
        }

        // Pattern: [projekt].d.[jahr].[nummer]
        if (preg_match('/^([a-z0-9-]+)\.([a-z])\.(\d{4})\.(\d+)$/i', $suffix, $matches)) {
            return $this->findNextAvailable(
                $prefix,
                fn (int $num) => sprintf('%s.%s.%s.%03d', $matches[1], $matches[2], $matches[3], $num),
                (int) $matches[4]
            );
        }

        // Pattern: gfz.[code].[jahr].[nummer]
        if (preg_match('/^gfz\.([a-z0-9]+)\.(\d{4})\.(\d+)$/i', $suffix, $matches)) {
            return $this->findNextAvailable(
                $prefix,
                fn (int $num) => sprintf('gfz.%s.%s.%03d', $matches[1], $matches[2], $num),
                (int) $matches[3]
            );
        }

        // Pattern: gfz.[s].[s].[jahr].[nummer]
        if (preg_match('/^gfz\.(\d+)\.(\d+)\.(\d{4})\.(\d+)$/i', $suffix, $matches)) {
            return $this->findNextAvailable(
                $prefix,
                fn (int $num) => sprintf('gfz.%s.%s.%s.%03d', $matches[1], $matches[2], $matches[3], $num),
                (int) $matches[4]
            );
        }

        // Pattern: [projekt]db.[nummer]
        if (preg_match('/^([a-z0-9]+db)\.(\d+)$/i', $suffix, $matches)) {
            return $this->findNextAvailable(
                $prefix,
                fn (int $num) => sprintf('%s.%d', $matches[1], $num),
                (int) $matches[2]
            );
        }

        // Pattern: [projekt]-[suffix].[nummer].[nummer]
        if (preg_match('/^([a-z]+-[a-z]+)\.(\d+)\.(\d+)$/i', $suffix, $matches)) {
            return $this->findNextAvailable(
                $prefix,
                fn (int $num) => sprintf('%s.%s.%d', $matches[1], $matches[2], $num),
                (int) $matches[3]
            );
        }

        // Pattern: igets.[station].l[level].[nummer]
        if (preg_match('/^(igets\.[a-z]+\.l\d+)\.(\d+)$/i', $suffix, $matches)) {
            return $this->findNextAvailable(
                $prefix,
                fn (int $num) => sprintf('%s.%03d', $matches[1], $num),
                (int) $matches[2]
            );
        }

        return null;
    }

    /**
     * Find the next available DOI by incrementing the number until one is available.
     *
     * @param  string  $prefix  The DOI prefix (e.g., "10.5880")
     * @param  callable  $suffixGenerator  Function that generates suffix from number
     * @param  int  $startNumber  The starting number to increment from
     *
     * @throws \RuntimeException If no available DOI is found within the maximum attempts
     */
    private function findNextAvailable(string $prefix, callable $suffixGenerator, int $startNumber): string
    {
        $maxAttempts = 100;
        $number = $startNumber + 1;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $candidateDoi = $prefix.'/'.$suffixGenerator($number);

            if (! $this->checkDoiExists($candidateDoi)) {
                return $candidateDoi;
            }

            $number++;
        }

        // Log warning and throw exception if no available DOI found
        \Log::warning('Could not find available DOI after {attempts} attempts', [
            'prefix' => $prefix,
            'start_number' => $startNumber,
            'attempts' => $maxAttempts,
        ]);

        throw new \RuntimeException(
            "Could not find an available DOI after {$maxAttempts} attempts. Please contact an administrator."
        );
    }

    /**
     * Generate a fallback suggestion when pattern is not recognized.
     *
     * Uses the base part of the suffix with current year and sequential number.
     * Uses PHP processing for cross-database compatibility (SQLite in tests, MySQL/MariaDB in production).
     */
    private function generateFallbackSuggestion(string $prefix, string $suffix): string
    {
        $year = (string) now()->year;

        // Try to extract a project identifier from the suffix
        if (preg_match('/^([a-z0-9-]+)/i', $suffix, $matches)) {
            $projectId = strtolower($matches[1]);

            // Find the highest number for this project and year
            // Use a more specific pattern that matches the expected DOI format: project.year.NNN
            $basePattern = $prefix.'/'.$projectId.'.'.$year.'.';

            // Query DOIs matching our pattern and find max number in PHP
            // This approach is compatible with both SQLite (tests) and MySQL/MariaDB (production)
            $dois = Resource::where('doi', 'LIKE', $basePattern.'%')
                ->pluck('doi');

            $maxNum = 0;
            foreach ($dois as $doi) {
                if (preg_match('/\.(\d+)$/', $doi, $numMatch)) {
                    $num = (int) $numMatch[1];
                    if ($num > $maxNum) {
                        $maxNum = $num;
                    }
                }
            }

            $nextNum = $maxNum > 0 ? $maxNum + 1 : 1;

            return sprintf('%s/%s.%s.%03d', $prefix, $projectId, $year, $nextNum);
        }

        // Ultimate fallback
        return sprintf('%s/new.%s.001', $prefix, $year);
    }
}

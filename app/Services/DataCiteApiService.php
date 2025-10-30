<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for fetching metadata from the DataCite API.
 * 
 * API Documentation: https://support.datacite.org/docs/api
 */
class DataCiteApiService
{
    private const API_BASE_URL = 'https://api.datacite.org';

    /**
     * Fetch metadata for a DOI from DataCite API.
     *
     * @param string $doi The DOI to fetch metadata for
     * @return array<string, mixed>|null The metadata array or null if not found
     */
    public function getMetadata(string $doi): ?array
    {
        try {
            // Clean DOI (remove https://doi.org/ prefix if present)
            $cleanDoi = str_replace('https://doi.org/', '', $doi);
            $cleanDoi = str_replace('http://doi.org/', '', $cleanDoi);

            $response = Http::timeout(10)
                ->get(self::API_BASE_URL . '/dois/' . urlencode($cleanDoi));

            if ($response->successful()) {
                $data = $response->json();
                return $data['data'] ?? null;
            }

            if ($response->status() === 404) {
                Log::info("DOI not found in DataCite: {$doi}");
                return null;
            }

            Log::warning("DataCite API error for DOI {$doi}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Failed to fetch DataCite metadata for DOI {$doi}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build a citation string from DataCite metadata.
     *
     * @param array<string, mixed> $metadata The metadata from DataCite API
     * @return string The formatted citation
     */
    public function buildCitationFromMetadata(array $metadata): string
    {
        $attributes = $metadata['attributes'] ?? [];

        // Extract authors
        $creators = $attributes['creators'] ?? [];
        $authorStrings = [];
        foreach ($creators as $creator) {
            if (isset($creator['familyName']) && isset($creator['givenName'])) {
                $authorStrings[] = $creator['familyName'] . ', ' . $creator['givenName'];
            } elseif (isset($creator['name'])) {
                $authorStrings[] = $creator['name'];
            }
        }
        $authors = !empty($authorStrings) ? implode('; ', $authorStrings) : 'Unknown Author';

        // Extract year
        $publicationYear = $attributes['publicationYear'] ?? 'n.d.';

        // Extract title
        $titles = $attributes['titles'] ?? [];
        $mainTitle = 'Untitled';
        foreach ($titles as $title) {
            if (!isset($title['titleType']) || $title['titleType'] === 'MainTitle') {
                $mainTitle = $title['title'] ?? 'Untitled';
                break;
            }
        }

        // Extract publisher
        $publisher = $attributes['publisher'] ?? 'Unknown Publisher';

        // Extract DOI
        $doi = $attributes['doi'] ?? $metadata['id'] ?? '';
        $doiUrl = $doi ? "https://doi.org/{$doi}" : '';

        // Build citation: [Authors] ([Year]): [Title]. [Publisher]. [DOI URL]
        return trim("{$authors} ({$publicationYear}): {$mainTitle}. {$publisher}. {$doiUrl}");
    }
}


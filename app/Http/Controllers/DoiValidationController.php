<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DoiValidationController extends Controller
{
    /**
     * Validate and resolve a DOI
     * 
     * Tries DataCite API first, then falls back to doi.org resolution check
     */
    public function validateDoi(Request $request): JsonResponse
    {
        $request->validate([
            'doi' => 'required|string',
        ]);

        $doi = trim($request->input('doi'));

        // Extract bare DOI if URL format
        if (preg_match('/^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)/i', $doi, $matches)) {
            $doi = $matches[1];
        }

        // Basic DOI format validation
        if (!preg_match('/^10\.\d{4,}(?:\.\d+)*\/\S+$/', $doi)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid DOI format',
            ], 400);
        }

        // Try DataCite API first
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->get("https://api.datacite.org/dois/{$doi}");

            if ($response->successful()) {
                $data = $response->json();
                $attributes = $data['data']['attributes'] ?? null;

                if ($attributes) {
                    return response()->json([
                        'success' => true,
                        'source' => 'datacite',
                        'metadata' => [
                            'title' => $attributes['titles'][0]['title'] ?? null,
                            'creators' => $this->extractCreators($attributes['creators'] ?? []),
                            'publicationYear' => $attributes['publicationYear'] ?? null,
                            'publisher' => $attributes['publisher'] ?? null,
                            'resourceType' => $attributes['types']['resourceType'] ?? null,
                        ],
                    ]);
                }
            }

            // DataCite returned 404 - try doi.org resolution
            if ($response->status() === 404) {
                return $this->checkDoiOrgResolution($doi);
            }

            return response()->json([
                'success' => false,
                'error' => "DataCite API error: {$response->status()}",
            ]);
        } catch (\Exception $e) {
            Log::warning('DOI validation error', [
                'doi' => $doi,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to validate DOI: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if DOI resolves via doi.org
     * This catches DOIs registered with providers other than DataCite (e.g., Crossref)
     */
    private function checkDoiOrgResolution(string $doi): JsonResponse
    {
        try {
            // HEAD request to check if DOI resolves
            $response = Http::timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->head("https://doi.org/{$doi}");

            // 302/301 means DOI exists and redirects
            if (in_array($response->status(), [301, 302])) {
                return response()->json([
                    'success' => true,
                    'source' => 'doi.org',
                    'metadata' => [
                        'title' => 'DOI registered (not in DataCite)',
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'DOI not found in DataCite registry',
            ]);
        } catch (\Exception $e) {
            Log::warning('doi.org resolution check failed', [
                'doi' => $doi,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'DOI not found in DataCite registry',
            ]);
        }
    }

    /**
     * Extract creator names from DataCite format
     * 
     * @param array<int, array{name?: string, givenName?: string, familyName?: string}> $creators
     * @return list<string>
     */
    private function extractCreators(array $creators): array
    {
        return array_map(function ($creator) {
            if (isset($creator['name'])) {
                return $creator['name'];
            }
            if (isset($creator['familyName']) && isset($creator['givenName'])) {
                return "{$creator['givenName']} {$creator['familyName']}";
            }
            return $creator['familyName'] ?? $creator['givenName'] ?? 'Unknown';
        }, $creators);
    }
}

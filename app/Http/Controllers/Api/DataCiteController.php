<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataCiteApiService;
use Illuminate\Http\JsonResponse;

/**
 * Controller für DOI-Zitations-Abruf.
 *
 * Verwendet die doi.org Content Negotiation API über den DataCiteApiService.
 */
class DataCiteController extends Controller
{
    public function __construct(
        private DataCiteApiService $dataCiteService
    ) {}

    /**
     * Ruft eine formatierte Zitation für eine DOI ab.
     *
     * @param  string  $doi  Die DOI (kann von jedem Registrar sein)
     * @return JsonResponse JSON mit citation und doi
     */
    public function getCitation(string $doi): JsonResponse
    {
        $metadata = $this->dataCiteService->getMetadata($doi);

        if (! $metadata) {
            return response()->json([
                'error' => 'Metadata not found for DOI',
            ], 404);
        }

        $citation = $this->dataCiteService->buildCitationFromMetadata($metadata);

        return response()->json([
            'citation' => $citation,
            'doi' => $doi,
        ]);
    }

    /**
     * Returns structured author data for a DOI.
     *
     * Extracts authors from CSL JSON metadata retrieved via doi.org Content Negotiation.
     * Used by the Relation Browser to display creator nodes in the graph.
     *
     * @param  string  $doi  The DOI (any registrar)
     * @return JsonResponse JSON with doi and authors array
     */
    public function getAuthors(string $doi): JsonResponse
    {
        $metadata = $this->dataCiteService->getMetadata($doi);

        if (! $metadata) {
            return response()->json([
                'error' => 'Metadata not found for DOI',
            ], 404);
        }

        $authors = $this->extractAuthors($metadata);

        return response()->json([
            'doi' => $doi,
            'authors' => $authors,
        ]);
    }

    /**
     * Extract structured author information from CSL JSON metadata.
     *
     * @param  array<string, mixed>  $metadata  CSL JSON metadata from doi.org
     * @return array<int, array{given_name: string|null, family_name: string|null, name: string|null, orcid: string|null}>
     */
    private function extractAuthors(array $metadata): array
    {
        $rawAuthors = $metadata['author'] ?? [];
        $authors = [];

        foreach ($rawAuthors as $author) {
            $orcid = $this->extractOrcid($author);

            if (isset($author['family'])) {
                $authors[] = [
                    'given_name' => $author['given'] ?? null,
                    'family_name' => $author['family'],
                    'name' => null,
                    'orcid' => $orcid,
                ];
            } elseif (isset($author['literal'])) {
                $authors[] = [
                    'given_name' => null,
                    'family_name' => null,
                    'name' => $author['literal'],
                    'orcid' => $orcid,
                ];
            }
        }

        return $authors;
    }

    /**
     * Extract ORCID from a CSL JSON author entry.
     *
     * CSL JSON may store ORCID in various fields depending on the registrar.
     *
     * @param  array<string, mixed>  $author  Single author entry from CSL JSON
     */
    private function extractOrcid(array $author): ?string
    {
        // Check common CSL JSON ORCID field names
        $orcidValue = $author['ORCID'] ?? $author['orcid'] ?? null;

        if (! is_string($orcidValue) || $orcidValue === '') {
            return null;
        }

        // Normalize: extract the ORCID ID from a full URL if needed
        if (preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/', $orcidValue, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

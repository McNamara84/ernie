<?php

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
}

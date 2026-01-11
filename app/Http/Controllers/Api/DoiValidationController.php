<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateDoiRequest;
use App\Services\DoiSuggestionService;
use Illuminate\Http\JsonResponse;

/**
 * API Controller for DOI validation.
 *
 * Validates DOIs against the local database and provides suggestions
 * for the next available DOI when duplicates are detected.
 */
class DoiValidationController extends Controller
{
    public function __construct(
        private readonly DoiSuggestionService $doiSuggestionService
    ) {}

    /**
     * Validate a DOI and check if it already exists.
     *
     * @return JsonResponse Returns validation result with suggestion if DOI exists
     */
    public function validate(ValidateDoiRequest $request): JsonResponse
    {
        $doi = $request->getDoi();
        $excludeResourceId = $request->getExcludeResourceId();

        // Validate DOI format
        if (! $this->doiSuggestionService->isValidDoiFormat($doi)) {
            return response()->json([
                'is_valid_format' => false,
                'exists' => false,
                'error' => 'Invalid DOI format. Expected format: 10.XXXX/suffix',
            ], 422);
        }

        // Check if DOI already exists
        $existingResource = $this->doiSuggestionService->getResourceByDoi($doi, $excludeResourceId);

        if ($existingResource === null) {
            // DOI is available
            return response()->json([
                'is_valid_format' => true,
                'exists' => false,
            ]);
        }

        // DOI already exists - provide suggestions
        $lastAssignedDoi = $this->doiSuggestionService->getLastAssignedDoi();
        $suggestedDoi = $this->doiSuggestionService->suggestNextDoi($doi);

        return response()->json([
            'is_valid_format' => true,
            'exists' => true,
            'existing_resource' => [
                'id' => $existingResource['id'],
                'title' => $existingResource['title'],
            ],
            'last_assigned_doi' => $lastAssignedDoi,
            'suggested_doi' => $suggestedDoi,
        ]);
    }
}

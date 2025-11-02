<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OrcidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * ORCID Controller
 *
 * Provides API endpoints for ORCID integration
 */
class OrcidController extends Controller
{
    /**
     * OrcidController constructor
     */
    public function __construct(
        private readonly OrcidService $orcidService
    ) {}

    /**
     * Validate ORCID ID
     *
     * GET /api/v1/orcid/validate/{orcid}
     *
     * @param  string  $orcid  The ORCID ID to validate
     */
    public function validate(string $orcid): JsonResponse
    {
        $result = $this->orcidService->validateOrcid($orcid);

        return response()->json($result);
    }

    /**
     * Fetch ORCID record
     *
     * GET /api/v1/orcid/{orcid}
     *
     * @param  string  $orcid  The ORCID ID
     */
    public function show(string $orcid): JsonResponse
    {
        $result = $this->orcidService->fetchOrcidRecord($orcid);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], $result['error'] === 'ORCID not found' ? 404 : 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }

    /**
     * Search for ORCID records
     *
     * GET /api/v1/orcid/search?q={query}&limit={limit}
     */
    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:200',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = $request->input('q');
        $limit = (int) $request->input('limit', 10);

        $result = $this->orcidService->searchOrcid($query, $limit);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
        ]);
    }
}

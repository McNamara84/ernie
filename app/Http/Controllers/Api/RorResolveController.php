<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RorResolveRequest;
use App\Services\RorLookupService;
use Illuminate\Http\JsonResponse;

/**
 * Resolve organization names to ROR identifiers using the local ROR data dump.
 *
 * Used by the frontend to match ORCID affiliations (which often lack ROR IDs)
 * against known ROR entries for smart deduplication.
 */
class RorResolveController extends Controller
{
    public function __invoke(RorResolveRequest $request, RorLookupService $rorLookup): JsonResponse
    {
        /** @var array<int, string> $names */
        $names = $request->validated('names');

        $results = [];

        foreach ($names as $name) {
            $match = $rorLookup->findByName($name);

            if ($match !== null) {
                $results[] = [
                    'name' => $name,
                    'rorId' => $match['rorId'],
                    'matchedName' => $match['value'],
                ];
            }
        }

        return response()->json(['results' => $results]);
    }
}

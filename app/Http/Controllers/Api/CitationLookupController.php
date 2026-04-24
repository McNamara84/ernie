<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Citations\CitationLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CitationLookupController extends Controller
{
    public function __construct(private readonly CitationLookupService $lookup) {}

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doi' => ['required', 'string', 'max:512'],
        ]);

        $result = $this->lookup->lookup($validated['doi']);

        return response()->json($result->toArray());
    }
}

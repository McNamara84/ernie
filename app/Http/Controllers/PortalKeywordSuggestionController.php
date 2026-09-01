<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PortalScope;
use App\Http\Requests\PortalKeywordSuggestionRequest;
use App\Services\KeywordSuggestionService;
use Illuminate\Http\JsonResponse;

final class PortalKeywordSuggestionController extends Controller
{
    public function __invoke(
        PortalKeywordSuggestionRequest $request,
        KeywordSuggestionService $keywordService,
        string $portalScope,
    ): JsonResponse {
        $scope = PortalScope::from($portalScope);

        return response()->json([
            'data' => $keywordService->searchFreeKeywordSuggestions($request->searchTerm(), scope: $scope),
        ]);
    }
}

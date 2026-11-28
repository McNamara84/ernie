<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CitationLabelReviewRequest;
use App\Models\User;
use App\Services\Citations\CitationLabelReviewService;
use Illuminate\Http\JsonResponse;

class CitationLabelReviewController extends Controller
{
    public function preview(CitationLabelReviewRequest $request, CitationLabelReviewService $review): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return response()->json($review->preview($user, $request->validated('ids'), $request->integer('cursor'), $request->validated('review_id')));
    }

    public function apply(CitationLabelReviewRequest $request, CitationLabelReviewService $review): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return response()->json(['results' => $review->apply($user, $request->validated('tokens'))]);
    }
}

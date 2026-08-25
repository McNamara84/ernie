<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Resource\SendResourceReviewLinksRequest;
use App\Services\Resources\ResourceReviewLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ResourceReviewLinkController extends Controller
{
    public function __construct(
        private readonly ResourceReviewLinkService $reviewLinkService,
    ) {}

    public function store(SendResourceReviewLinksRequest $request): JsonResponse
    {
        $contactAddress = trim((string) config('mail.landing_page_contact_cc'));

        if ($contactAddress === '' || filter_var($contactAddress, FILTER_VALIDATE_EMAIL) === false) {
            Log::error('Resource review email delivery is not configured with a valid contact address');

            return response()->json([
                'message' => 'Review email delivery is not configured. Please contact an administrator.',
            ], HttpResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        /** @var array{ids: array<int, int>} $validated */
        $validated = $request->validated();
        $resourceIds = array_values(array_unique($validated['ids']));

        $result = $this->reviewLinkService->queue($resourceIds, $contactAddress);

        $isPartial = $result['failed_resources'] !== [] || $result['skipped_recipients_count'] > 0;

        return response()->json([
            'message' => $isPartial
                ? 'Review emails were queued with some skipped recipients or resources.'
                : 'Review emails queued for delivery.',
            ...$result,
        ], $isPartial ? HttpResponse::HTTP_MULTI_STATUS : HttpResponse::HTTP_OK);
    }
}

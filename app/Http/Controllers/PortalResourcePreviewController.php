<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PortalScope;
use App\Services\PortalResourcePreviewService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class PortalResourcePreviewController extends Controller
{
    public function __construct(
        private readonly PortalResourcePreviewService $previewService,
    ) {}

    public function __invoke(int $resourceId, string $portalScope): JsonResponse
    {
        try {
            $preview = $this->previewService->find($resourceId, PortalScope::from($portalScope));
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'The citation preview is temporarily unavailable.',
            ], 503);
        }

        if ($preview === null) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        return response()->json($preview);
    }
}

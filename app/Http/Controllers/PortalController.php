<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PortalScope;
use App\Http\Requests\PortalSearchRequest;
use App\Services\BotProtection\PortalPageCacheService;
use App\Services\PortalPayloadService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the public portal page.
 *
 * The initial response deliberately contains only the paginated result list.
 * Map features are loaded asynchronously from PortalMapController so the page
 * payload remains bounded as the number of published resources grows.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly PortalPageCacheService $pageCache,
        private readonly PortalPayloadService $payloadService,
    ) {}

    public function index(PortalSearchRequest $request, string $portalScope): Response
    {
        $scope = PortalScope::from($portalScope);

        return Inertia::render('portal', $this->pageCache->remember(
            $request,
            fn (): array => $this->payloadService->build($request, $scope),
            $scope,
        ));
    }
}

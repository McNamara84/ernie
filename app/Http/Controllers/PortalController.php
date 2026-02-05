<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PortalSearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the public portal page.
 *
 * Provides a searchable, filterable interface for browsing published
 * research datasets with an interactive map display.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly PortalSearchService $searchService,
    ) {}

    /**
     * Display the portal page with search and filter capabilities.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'query' => $request->query('q'),
            'type' => $request->query('type', 'all'),
            'page' => (int) $request->query('page', 1),
            'per_page' => 20,
        ];

        // Get paginated results
        $paginator = $this->searchService->search($filters);

        // Transform resources for frontend
        $transformedResources = collect($paginator->items())
            ->map(fn ($resource) => $this->searchService->transformForPortal($resource))
            ->all();

        // Get map data (all matching resources with geo locations)
        $mapData = $this->searchService->getMapData($filters)
            ->map(fn ($resource) => $this->searchService->transformForPortal($resource))
            ->all();

        return Inertia::render('portal', [
            'resources' => $transformedResources,
            'mapData' => $mapData,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'filters' => [
                'query' => $filters['query'],
                'type' => $filters['type'],
            ],
        ]);
    }
}

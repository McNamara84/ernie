<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccessLevel;
use App\Models\Resource;
use App\Models\User;
use App\Services\EmbargoService;
use App\Services\GuidedTours\GuidedTourAssignmentService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly GuidedTourAssignmentService $guidedTourAssignmentService,
        private readonly ResourceListingProjectionRefreshService $projectionRefreshScheduler,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $guidedTour = $this->guidedTourAssignmentService->buildAutostartPayloadForRoute(
            user: $user,
            routeName: 'dashboard',
            shouldAutostart: (bool) $request->session()->get('guided_tours.autostart_after_login', false),
        );
        $this->projectionRefreshScheduler->flushPending();

        $recentResources = Resource::query()
            ->join('resource_listing_projections as listing', 'listing.resource_id', '=', 'resources.id')
            ->where('listing.is_igsn', false)
            ->where('listing.curator_user_id', $user->id)
            ->orderByDesc('resources.updated_at')
            ->orderByDesc('resources.id')
            ->take(5)
            ->get([
                'resources.id',
                'resources.updated_at',
                'listing.main_title as listing_main_title',
                'listing.workflow_status as listing_workflow_status',
            ])
            ->map(fn (Resource $resource): array => [
                'id' => $resource->id,
                'title' => $resource->getAttribute('listing_main_title') ?: 'Untitled Resource',
                'updated_at' => $resource->updated_at?->toISOString(),
                'status' => $resource->getAttribute('listing_workflow_status'),
            ])
            ->all();

        $embargoService = app(EmbargoService::class);
        $dueEmbargos = Resource::query()
            ->with(['dates.dateType', 'landingPage', 'titles.titleType'])
            ->where('access_level', AccessLevel::EMBARGOED->value)
            ->whereHas('dates', fn ($query) => $query
                ->whereHas('dateType', fn ($type) => $type->where('slug', 'Available'))
                ->where(function ($dateQuery): void {
                    $today = now(config('app.timezone'))->toDateString();
                    $dateQuery->where('date_value', '<=', $today)
                        ->orWhere('start_date', '<=', $today);
                }))
            ->where(function ($query): void {
                $query->whereDoesntHave('landingPage')
                    ->orWhereHas('landingPage', fn ($page) => $page->where('is_published', false));
            })
            ->get()
            ->filter(fn (Resource $resource): bool => $embargoService->isDue($resource))
            ->sortBy(fn (Resource $resource): string => (string) $embargoService->availableDate($resource))
            ->map(fn (Resource $resource): array => [
                'id' => $resource->id,
                'title' => $resource->titles->filter(fn ($title): bool => $title->isMainTitle())->pluck('value')->first() ?? 'Untitled Resource',
                'availableDate' => $embargoService->availableDate($resource),
            ])
            ->values();

        return Inertia::render('dashboard', [
            'recentResources' => $recentResources,
            'dueEmbargos' => $dueEmbargos->take(10)->all(),
            'dueEmbargoCount' => $dueEmbargos->count(),
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
            'guidedTour' => $guidedTour,
        ]);
    }
}

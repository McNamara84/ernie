<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScienceTopic;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Services\DataCentreCatalogService;
use App\Services\News\HomepageNewsService;
use App\Services\Usage\CounterReadService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class StaticPageController extends Controller
{
    public function home(HomepageNewsService $news): Response
    {
        abort_unless(config('public_pages.home_enabled'), 404);

        return Inertia::render('home', [
            'news' => $news->publicData(),
            'topics' => array_map(static fn (ScienceTopic $topic): array => $topic->forHomepage(), ScienceTopic::cases()),
        ]);
    }

    public function find(): Response
    {
        abort_unless(config('public_pages.find_enabled'), 404);

        return Inertia::render('find');
    }

    public function dataCentres(DataCentreCatalogService $catalog): Response
    {
        abort_unless(config('public_pages.data_centres_enabled'), 404);

        return Inertia::render('data-centres/index', ['dataCentres' => $catalog->published()]);
    }

    public function dataCentreDescription(DataCentreCatalogService $catalog): Response
    {
        abort_unless(config('public_pages.data_centre_description_enabled'), 404);

        return Inertia::render('data-centres/description', ['dataCentres' => $catalog->published()]);
    }

    public function about(): Response
    {
        return Inertia::render('about');
    }

    public function legalNotice(): Response
    {
        return Inertia::render('legal-notice');
    }

    public function usageStatistics(Request $request, CounterReadService $usage): Response
    {
        $id = filter_var($request->query('landing_page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $landingPage = $id === false ? null : LandingPage::query()
            ->where('is_published', true)
            ->where('template', '!=', 'external')
            ->with(['resource:id,resource_type_id', 'resource.resourceType:id,slug'])
            ->find($id);

        return Inertia::render('usage-statistics', [
            'usageStatus' => $usage->status(),
            'privacyUrl' => config('usage.privacy_url'),
            'returnTarget' => $landingPage === null ? null : [
                'href' => $landingPage->getPublicPath(),
                'kind' => LandingPageTemplate::expectedTemplateTypeForResource($landingPage->resource->resourceType?->slug),
            ],
        ]);
    }

    public function changelog(): Response
    {
        return Inertia::render('changelog');
    }
}

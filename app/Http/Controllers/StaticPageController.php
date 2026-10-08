<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScienceTopic;
use App\Services\DataCentreCatalogService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class StaticPageController extends Controller
{
    public function home(): Response|SymfonyResponse
    {
        if (! config('public_pages.home_enabled')) {
            return Inertia::location('https://dataservices.gfz-potsdam.de/');
        }

        return Inertia::render('home', [
            'topics' => array_map(static fn (ScienceTopic $topic): array => $topic->forHomepage(), ScienceTopic::cases()),
        ]);
    }

    public function find(): Response|SymfonyResponse
    {
        if (! config('public_pages.find_enabled')) {
            return Inertia::location('https://dataservices.gfz-potsdam.de/web/find');
        }

        return Inertia::render('find');
    }

    public function dataCentres(DataCentreCatalogService $catalog): Response|SymfonyResponse
    {
        if (! config('public_pages.data_centres_enabled')) {
            return Inertia::location('https://dataservices.gfz-potsdam.de/web/find/data-centres');
        }

        return Inertia::render('data-centres/index', ['dataCentres' => $catalog->published()]);
    }

    public function dataCentreDescription(DataCentreCatalogService $catalog): Response|SymfonyResponse
    {
        if (! config('public_pages.data_centre_description_enabled')) {
            return Inertia::location('https://dataservices.gfz-potsdam.de/web/find/data-centres/data-centre-description');
        }

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

    public function changelog(): Response
    {
        return Inertia::render('changelog');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScienceTopic;
use App\Services\DataCentreCatalogService;
use Inertia\Inertia;
use Inertia\Response;

final class StaticPageController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('home', [
            'topics' => array_map(static fn (ScienceTopic $topic): array => $topic->forHomepage(), ScienceTopic::cases()),
        ]);
    }

    public function find(): Response
    {
        return Inertia::render('find');
    }

    public function dataCentres(DataCentreCatalogService $catalog): Response
    {
        return Inertia::render('data-centres/index', ['dataCentres' => $catalog->published()]);
    }

    public function dataCentreDescription(DataCentreCatalogService $catalog): Response
    {
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

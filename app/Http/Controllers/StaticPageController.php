<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ScienceTopic;
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

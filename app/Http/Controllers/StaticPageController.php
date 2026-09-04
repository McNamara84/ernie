<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class StaticPageController extends Controller
{
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

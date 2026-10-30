<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexContactPersonsRequest;
use App\Services\ContactPersons\ContactPersonOverviewService;
use Inertia\Inertia;
use Inertia\Response;

final class ContactPersonController extends Controller
{
    public function __invoke(IndexContactPersonsRequest $request, ContactPersonOverviewService $overview): Response
    {
        $search = trim((string) $request->validated('search', ''));

        return Inertia::render('contact-persons/index', [
            ...$overview->overview($search, (int) ($request->validated('per_page') ?? 25), (int) ($request->validated('page') ?? 1)),
            'search' => $search,
        ]);
    }
}

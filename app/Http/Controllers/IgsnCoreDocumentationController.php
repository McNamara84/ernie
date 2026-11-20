<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Igsn\IgsnCoreDocumentationService;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

final class IgsnCoreDocumentationController extends Controller
{
    public function current(IgsnCoreDocumentationService $documentation): Response
    {
        return Inertia::render('schemas/igsn-core-docs', ['documentation' => $documentation->document()]);
    }

    public function revision(string $revision, IgsnCoreDocumentationService $documentation): Response
    {
        abort_unless(is_string(config('igsn_core.revisions.'.$revision)), 404);

        return Inertia::render('schemas/igsn-core-docs', ['documentation' => $documentation->document($revision)]);
    }

    public function example(string $example, IgsnCoreDocumentationService $documentation): HttpResponse
    {
        return $this->exampleResponse($example, $documentation);
    }

    public function revisionExample(string $revision, string $example, IgsnCoreDocumentationService $documentation): HttpResponse
    {
        abort_unless(is_string(config('igsn_core.revisions.'.$revision)), 404);

        return $this->exampleResponse($example, $documentation, $revision);
    }

    private function exampleResponse(string $example, IgsnCoreDocumentationService $documentation, ?string $revision = null): HttpResponse
    {
        abort_unless(in_array($example, ['minimal', 'full'], true), 404);

        return response($documentation->example($example, $revision), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="igsn-core-2.0-'.$example.'.xml"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

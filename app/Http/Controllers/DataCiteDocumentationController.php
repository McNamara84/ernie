<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DataCite\DataCiteDocumentationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

final class DataCiteDocumentationController extends Controller
{
    public function latest(): RedirectResponse
    {
        return redirect()->route('schemas.datacite.docs');
    }

    public function overview(DataCiteDocumentationService $documentation, ?string $revision = null): Response
    {
        $this->assertRevision($revision);

        return Inertia::render('schemas/datacite-docs-overview', ['documentation' => $documentation->overview($revision)]);
    }

    public function format(DataCiteDocumentationService $documentation, string $format, ?string $revision = null): Response
    {
        $this->assertRevision($revision);
        abort_unless(in_array($format, ['json', 'jsonld'], true), 404);

        return Inertia::render('schemas/datacite-docs', ['documentation' => $documentation->document($format, $revision)]);
    }

    public function example(DataCiteDocumentationService $documentation, string $format, string $example, string $extension, ?string $revision = null): HttpResponse
    {
        $this->assertRevision($revision);
        abort_unless(in_array($format, ['json', 'jsonld'], true) && $extension === $format && in_array($example, ['minimal', 'full'], true), 404);

        return response($documentation->example($format, $example, $revision), 200, [
            'Content-Type' => ($format === 'jsonld' ? 'application/ld+json' : 'application/json').'; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="datacite-4.7-'.$example.'.'.$extension.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function revisionFormat(string $revision, string $format, DataCiteDocumentationService $documentation): Response
    {
        return $this->format($documentation, $format, $revision);
    }

    public function revisionExample(string $revision, string $format, string $example, string $extension, DataCiteDocumentationService $documentation): HttpResponse
    {
        return $this->example($documentation, $format, $example, $extension, $revision);
    }

    private function assertRevision(?string $revision): void
    {
        abort_unless($revision === null || is_string(config('datacite.schemas.revisions.'.$revision)), 404);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DataCite\DataCiteSchemaPublicationService;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DataCiteSchemaController extends Controller
{
    public function current(Request $request, string $path, DataCiteSchemaPublicationService $schemas): Response
    {
        return $this->respond($request, $path, $schemas);
    }

    public function revision(Request $request, string $revision, string $path, DataCiteSchemaPublicationService $schemas): Response
    {
        return $this->respond($request, $path, $schemas, $revision);
    }

    private function respond(Request $request, string $path, DataCiteSchemaPublicationService $schemas, ?string $revision = null): Response
    {
        $file = $schemas->publicFile($path, $revision);
        abort_if($file === null, 404);

        $response = response((string) file_get_contents($file['path']), Response::HTTP_OK, [
            'Content-Type' => $file['type'].'; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $revision === null ? 'public, no-cache' : 'public, max-age=31536000, immutable',
        ]);
        $response->setEtag($file['hash']);
        $response->setLastModified(new DateTimeImmutable('@'.filemtime($file['path'])));
        $response->isNotModified($request);

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Igsn\Export\IgsnCoreSchemaResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class IgsnCoreSchemaController extends Controller
{
    public function current(Request $request, string $path, IgsnCoreSchemaResolver $schemas): BinaryFileResponse
    {
        return $this->respond($request, $path, $schemas);
    }

    public function revision(Request $request, string $revision, string $path, IgsnCoreSchemaResolver $schemas): BinaryFileResponse
    {
        abort_unless(is_string(config('igsn_core.revisions.'.$revision)), 404);

        return $this->respond($request, $path, $schemas, $revision);
    }

    private function respond(Request $request, string $path, IgsnCoreSchemaResolver $schemas, ?string $revision = null): BinaryFileResponse
    {
        $file = $schemas->publicFile($path, $revision);
        abort_if($file === null, 404);

        $response = response()->file($file['path'], [
            'Content-Type' => $file['type'].'; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $revision === null ? 'public, no-cache' : 'public, max-age=31536000, immutable',
        ]);
        $response->setEtag($file['hash']);
        $response->isNotModified($request);

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Igsn\Export\IgsnCoreSchemaResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class IgsnCoreSchemaController extends Controller
{
    public function __invoke(Request $request, string $snapshot, string $path, IgsnCoreSchemaResolver $schemas): BinaryFileResponse
    {
        abort_unless($snapshot === config('igsn_core.snapshot'), 404);
        $file = $schemas->publicFile($path);
        abort_if($file === null, 404);

        $response = response()->file($file['path'], [
            'Content-Type' => $file['type'].'; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
        $response->setEtag($file['hash']);
        $response->isNotModified($request);

        return $response;
    }
}

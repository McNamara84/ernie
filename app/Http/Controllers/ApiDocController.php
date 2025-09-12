<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class ApiDocController extends Controller
{
    /**
     * Return the OpenAPI documentation.
     */
    public function __invoke(Request $request): JsonResponse|View
    {
        $path = resource_path('data/openapi.json');

        try {
            if (! File::exists($path)) {
                throw new \RuntimeException('OpenAPI specification not found.');
            }

            $content = File::get($path);
            $spec = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'API specification unavailable',
                ], 500);
            }

            return response()->view('api-doc-error', status: 500);
        }

        if ($request->expectsJson()) {
            return response()->json($spec);
        }

        return view('api-doc', ['spec' => $spec]);
    }
}

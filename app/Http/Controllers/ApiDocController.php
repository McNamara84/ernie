<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class ApiDocController extends Controller
{
    /**
     * Return the OpenAPI documentation.
     *
     * URLs in the spec are dynamically replaced with the current APP_URL
     * to ensure correct URLs on all environments (local, stage, production).
     */
    public function __invoke(Request $request): JsonResponse|View|Response
    {
        $path = resource_path('data/openapi.json');

        try {
            if (! File::exists($path)) {
                throw new \RuntimeException('OpenAPI specification not found.');
            }

            $content = File::get($path);
            $spec = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            // Dynamically replace URLs with current APP_URL for correct environment
            $spec = $this->replaceUrlsWithAppUrl($spec);
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

    /**
     * Replace hardcoded URLs in the OpenAPI spec with the current APP_URL.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function replaceUrlsWithAppUrl(array $spec): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        // Replace termsOfService URL
        if (isset($spec['info']['termsOfService'])) {
            $spec['info']['termsOfService'] = $appUrl.'/legal-notice';
        }

        // Replace server URLs
        if (isset($spec['servers']) && is_array($spec['servers'])) {
            foreach ($spec['servers'] as $index => $server) {
                if (isset($server['url'])) {
                    $spec['servers'][$index]['url'] = $appUrl;
                }
            }
        }

        return $spec;
    }
}

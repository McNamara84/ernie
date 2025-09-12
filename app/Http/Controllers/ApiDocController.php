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
        $content = File::get($path);
        $spec = json_decode($content, true);

        if ($request->expectsJson()) {
            return response()->json($spec);
        }

        return view('api-doc', ['spec' => $spec]);
    }
}

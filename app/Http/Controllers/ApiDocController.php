<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class ApiDocController extends Controller
{
    /**
     * Return the OpenAPI documentation.
     */
    public function __invoke(): JsonResponse
    {
        $path = resource_path('data/openapi.json');
        $content = File::get($path);

        return response()->json(json_decode($content, true));
    }
}

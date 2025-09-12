<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use JsonException;

class ChangelogController extends Controller
{
    public function index(): JsonResponse
    {
        $path = resource_path('data/changelog.json');
        if (!File::exists($path)) {
            return response()->json([]);
        }

        try {
            $content = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return response()->json(['error' => 'Invalid changelog data'], 500);
        }

        return response()->json($content);
    }
}

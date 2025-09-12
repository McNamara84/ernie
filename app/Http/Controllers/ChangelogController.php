<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class ChangelogController extends Controller
{
    public function index(): JsonResponse
    {
        $path = resource_path('data/changelog.json');
        $content = [];
        if (File::exists($path)) {
            $content = json_decode(File::get($path), true);
        }
        return response()->json($content);
    }
}

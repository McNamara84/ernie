<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class VocabularyController extends Controller
{
    /**
     * Return GCMD Science Keywords vocabulary.
     */
    public function gcmdScienceKeywords(): JsonResponse
    {
        $filename = 'gcmd-science-keywords.json';

        if (!Storage::exists($filename)) {
            return response()->json([
                'error' => 'Vocabulary file not found. Please run: php artisan get-gcmd-science-keywords',
            ], 404);
        }

        $content = Storage::get($filename);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'error' => 'Failed to parse vocabulary file: ' . json_last_error_msg(),
            ], 500);
        }

        return response()->json($data);
    }
}

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
        return $this->getVocabulary(
            'gcmd-science-keywords.json',
            'php artisan get-gcmd-science-keywords'
        );
    }

    /**
     * Return GCMD Platforms vocabulary.
     */
    public function gcmdPlatforms(): JsonResponse
    {
        return $this->getVocabulary(
            'gcmd-platforms.json',
            'php artisan get-gcmd-platforms'
        );
    }

    /**
     * Return GCMD Instruments vocabulary.
     */
    public function gcmdInstruments(): JsonResponse
    {
        return $this->getVocabulary(
            'gcmd-instruments.json',
            'php artisan get-gcmd-instruments'
        );
    }

    /**
     * Generic method to retrieve a vocabulary file.
     */
    private function getVocabulary(string $filename, string $command): JsonResponse
    {
        if (!Storage::exists($filename)) {
            return response()->json([
                'error' => "Vocabulary file not found. Please run: {$command}",
            ], 404);
        }

        $content = Storage::get($filename);
        
        if ($content === null) {
            return response()->json([
                'error' => 'Failed to read vocabulary file',
            ], 500);
        }
        
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'error' => 'Failed to parse vocabulary file: ' . json_last_error_msg(),
            ], 500);
        }

        return response()->json($data);
    }
}

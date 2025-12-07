<?php

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Services\VocabularyCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class VocabularyController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly VocabularyCacheService $cacheService
    ) {
    }

    /**
     * Return GCMD Science Keywords vocabulary.
     */
    public function gcmdScienceKeywords(): JsonResponse
    {
        return $this->getCachedVocabulary(
            CacheKey::GCMD_SCIENCE_KEYWORDS,
            'gcmd-science-keywords.json',
            'php artisan get-gcmd-science-keywords'
        );
    }

    /**
     * Return GCMD Platforms vocabulary.
     */
    public function gcmdPlatforms(): JsonResponse
    {
        return $this->getCachedVocabulary(
            CacheKey::GCMD_PLATFORMS,
            'gcmd-platforms.json',
            'php artisan get-gcmd-platforms'
        );
    }

    /**
     * Return GCMD Instruments vocabulary.
     */
    public function gcmdInstruments(): JsonResponse
    {
        return $this->getCachedVocabulary(
            CacheKey::GCMD_INSTRUMENTS,
            'gcmd-instruments.json',
            'php artisan get-gcmd-instruments'
        );
    }

    /**
     * Return MSL Vocabulary (EPOS Multi-Scale Laboratories).
     */
    public function mslVocabulary(): JsonResponse
    {
        return $this->getCachedVocabulary(
            CacheKey::MSL_KEYWORDS,
            'msl-vocabulary.json',
            'php artisan get-msl-keywords'
        );
    }

    /**
     * Generic method to retrieve a cached vocabulary file.
     */
    private function getCachedVocabulary(
        CacheKey $cacheKey,
        string $filename,
        string $command
    ): JsonResponse {
        try {
            $data = $this->cacheService->cacheVocabulary(
                $cacheKey,
                function () use ($filename, $command): array {
                    if (! Storage::exists($filename)) {
                        throw new \RuntimeException("Vocabulary file not found. Please run: {$command}");
                    }

                    $content = Storage::get($filename);

                    if ($content === null) {
                        throw new \RuntimeException('Failed to read vocabulary file.');
                    }

                    $decoded = json_decode($content, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \RuntimeException('Invalid JSON in vocabulary file: ' . json_last_error_msg());
                    }

                    return $decoded;
                }
            );

            return response()->json($data);
        } catch (\RuntimeException $e) {
            // Determine appropriate HTTP status code based on error type
            $statusCode = 500; // Default: Internal Server Error
            
            if (str_contains($e->getMessage(), 'not found')) {
                $statusCode = 404; // Not Found
            } elseif (str_contains($e->getMessage(), 'Invalid JSON')) {
                $statusCode = 500; // Internal Server Error (corrupted data)
            } elseif (str_contains($e->getMessage(), 'Failed to read')) {
                $statusCode = 500; // Internal Server Error (I/O problem)
            }

            return response()->json([
                'error' => $e->getMessage(),
            ], $statusCode);
        }
    }
}

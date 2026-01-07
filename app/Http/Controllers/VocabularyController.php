<?php

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Exceptions\VocabularyCorruptedException;
use App\Exceptions\VocabularyNotFoundException;
use App\Exceptions\VocabularyReadException;
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
    ) {}

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
                        throw new VocabularyNotFoundException($command);
                    }

                    $content = Storage::get($filename);

                    if ($content === null) {
                        throw new VocabularyReadException;
                    }

                    $decoded = json_decode($content, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new VocabularyCorruptedException(json_last_error_msg());
                    }

                    // Ensure decoded data is not null (e.g., if JSON contains literal "null")
                    if ($decoded === null) {
                        throw new VocabularyCorruptedException('Vocabulary file contains null data');
                    }

                    return $decoded;
                }
            );

            return response()->json($data);
        } catch (VocabularyNotFoundException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 404);
        } catch (VocabularyReadException|VocabularyCorruptedException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

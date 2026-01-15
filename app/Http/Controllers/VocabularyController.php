<?php

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Exceptions\VocabularyCorruptedException;
use App\Exceptions\VocabularyNotFoundException;
use App\Exceptions\VocabularyReadException;
use App\Models\ThesaurusSetting;
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
        if (!$this->isThesaurusActive(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS)) {
            return response()->json(['error' => 'Thesaurus is disabled'], 404);
        }

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
        if (!$this->isThesaurusActive(ThesaurusSetting::TYPE_PLATFORMS)) {
            return response()->json(['error' => 'Thesaurus is disabled'], 404);
        }

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
        if (!$this->isThesaurusActive(ThesaurusSetting::TYPE_INSTRUMENTS)) {
            return response()->json(['error' => 'Thesaurus is disabled'], 404);
        }

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

    /**
     * Return thesauri availability status for the frontend.
     *
     * This endpoint is always used by the ERNIE frontend, so we check
     * is_active (not is_elmo_active) regardless of the route prefix.
     */
    public function thesauriAvailability(): JsonResponse
    {
        $thesauri = ThesaurusSetting::all()->mapWithKeys(fn (ThesaurusSetting $t) => [
            $t->type => [
                'available' => $t->is_active,
                'displayName' => $t->display_name,
            ],
        ]);

        return response()->json($thesauri);
    }

    /**
     * Check if a thesaurus is active for the current request context.
     */
    private function isThesaurusActive(string $type): bool
    {
        $setting = ThesaurusSetting::where('type', $type)->first();

        if (!$setting) {
            return true; // Default to active if no setting exists
        }

        // For ELMO (API) requests, check is_elmo_active
        // For ERNIE requests, check is_active
        return $this->isElmoRequest() ? $setting->is_elmo_active : $setting->is_active;
    }

    /**
     * Determine if the current request is an ELMO API request.
     *
     * ELMO requests come through the /api/* routes with the elmo.api-key middleware
     * and/or contain an X-API-Key header.
     *
     * Note: Some /api/* routes like thesauri-availability are unauthenticated and
     * used by the ERNIE frontend. Those routes should explicitly check is_active
     * instead of relying on this method. See thesauriAvailability() for example.
     */
    private function isElmoRequest(): bool
    {
        // Check if request path starts with 'api/' or has API key header
        return request()->is('api/*') || request()->hasHeader('X-API-Key');
    }
}

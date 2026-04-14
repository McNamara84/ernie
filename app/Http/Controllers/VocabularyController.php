<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Exceptions\VocabularyCorruptedException;
use App\Exceptions\VocabularyNotFoundException;
use App\Exceptions\VocabularyReadException;
use App\Models\PidSetting;
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
     * Return thesauri availability status.
     *
     * Context-aware: returns is_elmo_active for ELMO API requests
     * and is_active for ERNIE frontend requests.
     */
    public function thesauriAvailability(): JsonResponse
    {
        $isElmo = $this->isElmoRequest();

        $thesauri = ThesaurusSetting::all()->mapWithKeys(fn (ThesaurusSetting $t) => [
            $t->type => [
                'available' => $isElmo ? $t->is_elmo_active : $t->is_active,
                'displayName' => $t->display_name,
            ],
        ]);

        return response()->json($thesauri);
    }

    /**
     * Return PID4INST instruments vocabulary.
     */
    public function pid4instInstruments(): JsonResponse
    {
        if (!$this->isPidActive(PidSetting::TYPE_PID4INST)) {
            return response()->json(['error' => 'PID4INST is disabled'], 404);
        }

        return $this->getCachedVocabulary(
            CacheKey::PID4INST_INSTRUMENTS,
            'pid4inst-instruments.json',
            'php artisan get-pid4inst-instruments'
        );
    }

    /**
     * Return ICS Chronostratigraphy vocabulary.
     */
    public function chronostratTimescale(): JsonResponse
    {
        if (!$this->isThesaurusActive(ThesaurusSetting::TYPE_CHRONOSTRAT)) {
            return response()->json(['error' => 'Thesaurus is disabled'], 404);
        }

        return $this->getCachedVocabulary(
            CacheKey::CHRONOSTRAT_TIMESCALE,
            'chronostrat-timescale.json',
            'php artisan get-chronostrat-timescale'
        );
    }

    /**
     * Return GEMET Thesaurus vocabulary.
     */
    public function gemetThesaurus(): JsonResponse
    {
        if (!$this->isThesaurusActive(ThesaurusSetting::TYPE_GEMET)) {
            return response()->json(['error' => 'Thesaurus is disabled'], 404);
        }

        return $this->getCachedVocabulary(
            CacheKey::GEMET_THESAURUS,
            'gemet-thesaurus.json',
            'php artisan get-gemet-thesaurus'
        );
    }

    /**
     * Return Analytical Methods for Geochemistry vocabulary.
     */
    public function analyticalMethods(): JsonResponse
    {
        if (!$this->isThesaurusActive(ThesaurusSetting::TYPE_ANALYTICAL_METHODS)) {
            return response()->json(['error' => 'Thesaurus is disabled'], 404);
        }

        return $this->getCachedVocabulary(
            CacheKey::ANALYTICAL_METHODS,
            'analytical-methods.json',
            'php artisan get-analytical-methods'
        );
    }

    /**
     * Return ROR affiliations vocabulary for ELMO.
     *
     * Returns the complete ROR data with metadata wrapper
     * including total count and last update timestamp.
     */
    public function rorAffiliations(): JsonResponse
    {
        if (!$this->isPidActive(PidSetting::TYPE_ROR)) {
            return response()->json(['error' => 'ROR is disabled'], 404);
        }

        $filePath = 'ror/ror-affiliations.json';

        if (! Storage::exists($filePath)) {
            return response()->json([
                'error' => 'Vocabulary file not found. Please run: php artisan get-ror-ids',
            ], 404);
        }

        $content = Storage::get($filePath);

        if ($content === null) {
            return response()->json([
                'error' => 'Failed to read vocabulary file.',
            ], 500);
        }

        // Stream the raw JSON string directly to avoid decoding the large file into memory
        return new JsonResponse(data: $content, json: true);
    }

    /**
     * Return PID availability status.
     *
     * Context-aware: returns is_elmo_active for ELMO API requests
     * and is_active for ERNIE frontend requests.
     */
    public function pidAvailability(): JsonResponse
    {
        $isElmo = $this->isElmoRequest();

        $pids = PidSetting::all()->mapWithKeys(fn (PidSetting $p) => [
            $p->type => [
                'available' => $isElmo ? $p->is_elmo_active : $p->is_active,
                'displayName' => $p->display_name,
            ],
        ]);

        return response()->json($pids);
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
     * Check if a PID registry is active for the current request context.
     */
    private function isPidActive(string $type): bool
    {
        $setting = PidSetting::where('type', $type)->first();

        if (!$setting) {
            return true; // Default to active if no setting exists
        }

        return $this->isElmoRequest() ? $setting->is_elmo_active : $setting->is_active;
    }

    /**
     * Determine if the current request is an ELMO API request.
     *
     * ELMO requests are identified by the presence of the ernie.api-key middleware
     * on the current route. This is more reliable than URL pattern matching
     * because some /api/* routes (like thesauri-availability) are used by
     * the ERNIE frontend and should not be treated as ELMO requests.
     */
    private function isElmoRequest(): bool
    {
        $route = request()->route();

        // Check if the current route has the ernie.api-key middleware applied
        if ($route !== null) {
            $middleware = $route->gatherMiddleware();

            return in_array('ernie.api-key', $middleware, true);
        }

        // Fallback: check for X-API-Key header (for requests outside Laravel routing)
        return request()->hasHeader('X-API-Key');
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;

class RorAffiliationController extends Controller
{
    private const STORAGE_DISK = 'local';

    private const STORAGE_PATH = 'ror/ror-affiliations.json';

    /**
     * Check if the current cache store supports tagging.
     */
    private function supportsTagging(): bool
    {
        return method_exists(Cache::getStore(), 'tags');
    }

    public function __invoke(): JsonResponse
    {
        // Cache ROR affiliations for 7 days
        $cacheInstance = $this->supportsTagging()
            ? Cache::tags(CacheKey::ROR_AFFILIATION->tags())
            : Cache::store();

        $data = $cacheInstance->remember(
                CacheKey::ROR_AFFILIATION->key(),
                CacheKey::ROR_AFFILIATION->ttl(),
                function (): array {
                    $disk = Storage::disk(self::STORAGE_DISK);

                    if (! $disk->exists(self::STORAGE_PATH)) {
                        return [];
                    }

                    try {
                        $contents = $disk->get(self::STORAGE_PATH);

                        if (! is_string($contents)) {
                            Log::warning('Cached ROR affiliations returned non-string contents.', [
                                'path' => self::STORAGE_PATH,
                                'type' => get_debug_type($contents),
                            ]);

                            return [];
                        }

                        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        Log::error('Failed to decode cached ROR affiliations.', [
                            'message' => $exception->getMessage(),
                            'path' => self::STORAGE_PATH,
                        ]);

                        return [];
                    }

                    if (! is_array($decoded)) {
                        return [];
                    }

                    return $decoded;
                }
            );

        return response()->json($data);
    }
}

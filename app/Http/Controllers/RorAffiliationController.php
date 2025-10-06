<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;

class RorAffiliationController extends Controller
{
    private const STORAGE_DISK = 'local';
    private const STORAGE_PATH = 'ror/ror-affiliations.json';

    public function __invoke(): JsonResponse
    {
        $disk = Storage::disk(self::STORAGE_DISK);

        if (!$disk->exists(self::STORAGE_PATH)) {
            return response()->json([]);
        }

        try {
            $contents = $disk->get(self::STORAGE_PATH);

            if (!is_string($contents)) {
                Log::warning('Cached ROR affiliations returned non-string contents.', [
                    'path' => self::STORAGE_PATH,
                    'type' => get_debug_type($contents),
                ]);

                return response()->json([], 500);
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::error('Failed to decode cached ROR affiliations.', [
                'message' => $exception->getMessage(),
                'path' => self::STORAGE_PATH,
            ]);

            return response()->json([], 500);
        }

        if (!is_array($decoded)) {
            return response()->json([]);
        }

        return response()->json($decoded);
    }
}

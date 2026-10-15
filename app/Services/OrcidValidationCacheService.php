<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/** Local evidence is authoritative even when online verification is deferred. */
final class OrcidValidationCacheService
{
    /** @return array{valid: bool, exists: bool, message: string, errorType: string|null}|null */
    public function get(string $bareId): ?array
    {
        if (Cache::has('orcid:negative:'.$bareId)) {
            return ['valid' => true, 'exists' => false, 'message' => 'ORCID ID not found', 'errorType' => 'not_found'];
        }
        if (Cache::has('orcid:verified:'.$bareId)) {
            return ['valid' => true, 'exists' => true, 'message' => 'Valid ORCID ID', 'errorType' => null];
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use Closure;
use Throwable;

abstract class CachedAwardSource implements AwardSource
{
    use ChecksCacheTagging;

    public function __construct(protected readonly AwardHttpClient $http) {}

    /** @param Closure(): AwardSourceResult $load */
    protected function cached(string $provider, string $number, FunderIdentity $funder, Closure $load): AwardSourceResult
    {
        $cacheKey = CacheKey::FUNDING_AWARD_URI_SOURCE;
        $key = $cacheKey->key($provider.':'.AwardMetadata::hash([
            'number' => $number, 'ids' => $funder->ids, 'programmes' => $funder->cordisProgrammes(),
            'version' => config('funding_award_uri.rules_version'),
        ]));
        try {
            $cache = $this->getCacheInstance($cacheKey->tags());
            $cached = $cache->get($key);
            if ($cached instanceof AwardSourceResult) {
                return $cached;
            }
            $result = $load();
            if ($result->error === null) {
                $cache->put($key, $result, $result->candidates === []
                    ? (int) config('funding_award_uri.negative_cache_seconds', 3600) : $cacheKey->ttl());
            }

            return $result;
        } catch (Throwable) {
            return new AwardSourceResult(error: 'source_unavailable');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

abstract class CachedAwardSource implements AwardSource
{
    public function __construct(protected readonly AwardHttpClient $http) {}

    /** @param Closure(): AwardSourceResult $load */
    protected function cached(string $provider, string $number, FunderIdentity $funder, Closure $load): AwardSourceResult
    {
        $key = 'award-uri:source:'.$provider.':'.AwardMetadata::hash([
            'number' => $number, 'ids' => $funder->ids, 'programmes' => $funder->cordisProgrammes(),
            'version' => config('funding_award_uri.rules_version'),
        ]);
        try {
            $cached = Cache::get($key);
            if ($cached instanceof AwardSourceResult) {
                return $cached;
            }
            $result = $load();
            if ($result->error === null) {
                Cache::put($key, $result, (int) config($result->candidates === []
                    ? 'funding_award_uri.negative_cache_seconds' : 'funding_award_uri.positive_cache_seconds'));
            }

            return $result;
        } catch (Throwable) {
            return new AwardSourceResult(error: 'source_unavailable');
        }
    }
}

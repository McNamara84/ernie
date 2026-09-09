<?php

declare(strict_types=1);

namespace App\Services\PublicTraffic;

use App\Models\PublicTrafficHourlyStatistic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class PublicTrafficAvailabilityService
{
    public function __construct(private PublicTrafficAggregateStore $store) {}

    public function observe(): ?PublicTrafficHourlyStatistic
    {
        if (! config('public_traffic.enabled')) {
            return null;
        }

        $response = Http::acceptJson()
            ->connectTimeout((int) config('public_traffic.health_connect_timeout_seconds', 2))
            ->timeout((int) config('public_traffic.health_timeout_seconds', 4))
            ->get((string) config('public_traffic.health_url'));

        if (! $response->successful() || $response->json('status') !== 'ok') {
            throw new RuntimeException('The public ERNIE health endpoint did not return the expected healthy response.');
        }

        $this->assertCacheIsWritable();

        return $this->store->observeMinute(CarbonImmutable::now('UTC'));
    }

    private function assertCacheIsWritable(): void
    {
        $key = 'public-traffic:health:'.Str::random(24);
        $value = Str::random(24);

        try {
            Cache::put($key, $value, 10);

            if (! hash_equals($value, (string) Cache::get($key))) {
                throw new RuntimeException('The public traffic deduplication cache is not readable and writable.');
            }
        } finally {
            Cache::forget($key);
        }
    }
}

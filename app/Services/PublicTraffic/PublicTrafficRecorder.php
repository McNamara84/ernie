<?php

declare(strict_types=1);

namespace App\Services\PublicTraffic;

use App\Enums\PublicTrafficSurface;
use App\Services\BotProtection\BotClassifierService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final readonly class PublicTrafficRecorder
{
    public function __construct(
        private BotClassifierService $botClassifier,
        private PublicTrafficAggregateStore $store,
        private PublicTrafficWarningLogger $warningLogger,
    ) {}

    public function record(Request $request, PublicTrafficSurface $surface): void
    {
        if (! config('public_traffic.enabled') || $request->user() !== null) {
            return;
        }

        $userAgent = trim((string) $request->userAgent());
        if ($userAgent === '' || $this->botClassifier->isKnownBot($userAgent)) {
            return;
        }

        $createdKeys = [];

        try {
            $now = CarbonImmutable::now('UTC');
            $bucketStartedAt = $now->startOfHour();
            $fingerprint = $this->fingerprint($request, $userAgent, $bucketStartedAt);
            $expiresAt = $bucketStartedAt
                ->addHour()
                ->addSeconds((int) config('public_traffic.deduplication_grace_seconds', 300));
            $keyPrefix = 'public-traffic:visitor:'.$bucketStartedAt->format('YmdH');
            $surfaceKey = "{$keyPrefix}:{$surface->value}:{$fingerprint}";
            $combinedKey = "{$keyPrefix}:combined:{$fingerprint}";

            $incrementSurface = Cache::add($surfaceKey, true, $expiresAt);
            if ($incrementSurface) {
                $createdKeys[] = $surfaceKey;
            }

            $incrementCombined = Cache::add($combinedKey, true, $expiresAt);
            if ($incrementCombined) {
                $createdKeys[] = $combinedKey;
            }

            $this->store->increment($bucketStartedAt, $surface, $incrementSurface, $incrementCombined);
        } catch (Throwable $exception) {
            foreach ($createdKeys as $key) {
                try {
                    Cache::forget($key);
                } catch (Throwable) {
                    // The public request must remain available when analytics fail.
                }
            }

            $this->warningLogger->warning(
                'recording',
                'Failed to record anonymous public traffic.',
                $exception,
            );
        }
    }

    private function fingerprint(Request $request, string $userAgent, CarbonImmutable $bucketStartedAt): string
    {
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new RuntimeException('APP_KEY is required for anonymous public traffic deduplication.');
        }

        return hash_hmac('sha256', implode('|', [
            $bucketStartedAt->toIso8601String(),
            (string) $request->ip(),
            $userAgent,
        ]), $secret);
    }
}

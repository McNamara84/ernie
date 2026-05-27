<?php

declare(strict_types=1);

namespace App\Services\BotProtection;

use App\Models\LandingPage;
use App\Services\Statistics\LandingPageAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LandingPageViewCounterService
{
    public function __construct(
        private readonly BotClassifierService $botClassifier,
        private readonly LandingPageAnalyticsService $analyticsService,
    ) {}

    public function record(Request $request, LandingPage $landingPage): void
    {
        if (! (bool) config('bot_protection.enabled', true)) {
            $this->recordView($landingPage);

            return;
        }

        if ($this->botClassifier->isKnownAiBot($request)) {
            return;
        }

        $debounceSeconds = max(0, (int) config('bot_protection.view_count_debounce_seconds', 3600));

        if ($debounceSeconds === 0) {
            $this->recordView($landingPage);

            return;
        }

        if (Cache::add($this->debounceKey($request, $landingPage), true, $debounceSeconds)) {
            $this->recordView($landingPage);
        }
    }

    private function recordView(LandingPage $landingPage): void
    {
        $landingPage->incrementViewCount();
        $this->analyticsService->recordPageView($landingPage);
    }

    private function debounceKey(Request $request, LandingPage $landingPage): string
    {
        $visitorFingerprint = hash('sha256', implode('|', [
            (string) $request->ip(),
            (string) $request->userAgent(),
        ]));

        return "landing-page:view-count:{$landingPage->id}:{$visitorFingerprint}";
    }
}

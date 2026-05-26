<?php

declare(strict_types=1);

namespace App\Services\BotProtection;

use App\Models\LandingPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LandingPageViewCounterService
{
    public function __construct(
        private readonly BotClassifierService $botClassifier,
    ) {}

    public function record(Request $request, LandingPage $landingPage): void
    {
        if (! (bool) config('bot_protection.enabled', true)) {
            $landingPage->incrementViewCount();

            return;
        }

        if ($this->botClassifier->isKnownAiBot($request)) {
            return;
        }

        $debounceSeconds = max(0, (int) config('bot_protection.view_count_debounce_seconds', 3600));

        if ($debounceSeconds === 0) {
            $landingPage->incrementViewCount();

            return;
        }

        if (Cache::add($this->debounceKey($request, $landingPage), true, $debounceSeconds)) {
            $landingPage->incrementViewCount();
        }
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

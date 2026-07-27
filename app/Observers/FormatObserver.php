<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Format;
use App\Models\LandingPage;
use App\Services\BotProtection\LandingPageRenderDataCacheService;

final class FormatObserver
{
    public function __construct(private readonly LandingPageRenderDataCacheService $renderDataCache) {}

    public function saved(Format $format): void
    {
        $this->forget($format);
    }

    public function deleted(Format $format): void
    {
        $this->forget($format);
    }

    private function forget(Format $format): void
    {
        $landingPageId = LandingPage::query()
            ->where('resource_id', $format->resource_id)
            ->value('id');

        if (is_numeric($landingPageId)) {
            $this->renderDataCache->forgetById((int) $landingPageId);
        }
    }
}

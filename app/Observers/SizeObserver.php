<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LandingPage;
use App\Models\Size;
use App\Services\BotProtection\LandingPageRenderDataCacheService;

final class SizeObserver
{
    public function __construct(private readonly LandingPageRenderDataCacheService $renderDataCache) {}

    public function saved(Size $size): void
    {
        $this->forget($size);
    }

    public function deleted(Size $size): void
    {
        $this->forget($size);
    }

    private function forget(Size $size): void
    {
        $landingPageId = LandingPage::query()
            ->where('resource_id', $size->resource_id)
            ->value('id');

        if (is_numeric($landingPageId)) {
            $this->renderDataCache->forgetById((int) $landingPageId);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LandingPageLink;
use App\Services\BotProtection\LandingPageRenderDataCacheService;

final class LandingPageLinkObserver
{
    public function __construct(private readonly LandingPageRenderDataCacheService $renderDataCache) {}

    public function saved(LandingPageLink $link): void
    {
        $this->renderDataCache->forgetById($link->landing_page_id);
    }

    public function deleted(LandingPageLink $link): void
    {
        $this->renderDataCache->forgetById($link->landing_page_id);
    }
}

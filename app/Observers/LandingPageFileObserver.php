<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LandingPageFile;
use App\Services\BotProtection\LandingPageRenderDataCacheService;

final class LandingPageFileObserver
{
    public function __construct(private readonly LandingPageRenderDataCacheService $renderDataCache) {}

    public function saved(LandingPageFile $file): void
    {
        $this->renderDataCache->forgetById($file->landing_page_id);
    }

    public function deleted(LandingPageFile $file): void
    {
        $this->renderDataCache->forgetById($file->landing_page_id);
    }
}

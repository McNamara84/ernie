<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LandingPage;
use App\Models\LandingPageFile;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\LandingPageDownloadAvailabilityService;
use App\Services\LandingPageDownloadUrlSuggestionService;
use Illuminate\Support\Facades\DB;

final class LandingPageFileObserver
{
    public function __construct(private readonly LandingPageRenderDataCacheService $renderDataCache) {}

    public function creating(LandingPageFile $file): void
    {
        DB::transaction(function () use ($file): void {
            $landingPage = LandingPage::query()->lockForUpdate()->find($file->landing_page_id);
            if ($landingPage !== null) {
                app(LandingPageDownloadAvailabilityService::class)->normalizeEmptySuppression($landingPage);
                if ($landingPage->isDirty('downloads_unavailable')) {
                    $landingPage->save();
                }
            }
        });
    }

    public function saved(LandingPageFile $file): void
    {
        $this->renderDataCache->forgetById($file->landing_page_id);
        LandingPageDownloadUrlSuggestionService::forgetAfterCommit();
    }

    public function deleted(LandingPageFile $file): void
    {
        $this->renderDataCache->forgetById($file->landing_page_id);
        LandingPageDownloadUrlSuggestionService::forgetAfterCommit();
    }
}

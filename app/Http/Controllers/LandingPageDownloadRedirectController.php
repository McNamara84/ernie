<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LandingPage;
use App\Models\LandingPageFile;
use App\Services\BotProtection\BotClassifierService;
use App\Services\Statistics\LandingPageAnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LandingPageDownloadRedirectController extends Controller
{
    public function __construct(
        private readonly BotClassifierService $botClassifier,
        private readonly LandingPageAnalyticsService $analyticsService,
    ) {}

    public function primary(Request $request, LandingPage $landingPage): RedirectResponse
    {
        abort_if(! $landingPage->isPublished(), HttpResponse::HTTP_NOT_FOUND, 'Download not found');

        $targetUrl = $landingPage->ftp_url;

        abort_if(! is_string($targetUrl) || trim($targetUrl) === '', HttpResponse::HTTP_NOT_FOUND, 'Download not found');

        $this->recordDownloadClick($request, $landingPage);

        return redirect()->away($targetUrl, HttpResponse::HTTP_FOUND);
    }

    public function file(Request $request, LandingPage $landingPage, LandingPageFile $landingPageFile): RedirectResponse
    {
        abort_if(! $landingPage->isPublished(), HttpResponse::HTTP_NOT_FOUND, 'Download not found');
        abort_if($landingPageFile->landing_page_id !== $landingPage->id, HttpResponse::HTTP_NOT_FOUND, 'Download not found');

        $targetUrl = $landingPageFile->url;

        abort_if(trim($targetUrl) === '', HttpResponse::HTTP_NOT_FOUND, 'Download not found');

        $this->recordDownloadClick($request, $landingPage);

        return redirect()->away($targetUrl, HttpResponse::HTTP_FOUND);
    }

    private function recordDownloadClick(Request $request, LandingPage $landingPage): void
    {
        if ((bool) config('bot_protection.enabled', true) && $this->botClassifier->isKnownAiBot($request)) {
            return;
        }

        $this->analyticsService->recordFileDownloadClick($landingPage);
    }
}
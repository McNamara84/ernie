<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use Database\Seeders\PlaywrightTestSeeder;
use Illuminate\Foundation\Vite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses()->group('issue-1051', 'issue-1146', 'browser', 'landing-pages');

describe('Issues 1051 and 1146 responsive landing page header logo', function (): void {
    beforeEach(function (): void {
        app(Vite::class)
            ->useHotFile(storage_path('framework/testing-vite.hot'))
            ->useBuildDirectory('build');
    });

    it('uses the natural logo width up to the available header width', function (): void {
        /** @var TestCase $this */
        $this->seed(PlaywrightTestSeeder::class);

        $landingPage = LandingPage::query()
            ->where('slug', 'playwright-published')
            ->firstOrFail();

        $logoPath = 'landing-page-logos/browser/issue-1051-responsive-logo.png';
        $logo = UploadedFile::fake()->image('issue-1051-responsive-logo.png', 1200, 240);

        Storage::disk('public')->put($logoPath, $logo->getContent());

        try {
            $template = LandingPageTemplate::factory()->create([
                'name' => 'Issue 1051 Responsive Logo',
                'logo_path' => $logoPath,
                'logo_filename' => $logo->getClientOriginalName(),
            ]);

            $landingPage->update(['landing_page_template_id' => $template->id]);
            app(LandingPageRenderDataCacheService::class)->forget($landingPage);

            $browserUrl = parse_url($landingPage->public_url, PHP_URL_PATH);

            expect($browserUrl)->toBeString()->not->toBe('');

            $page = visit($browserUrl)
                ->resize(1440, 900)
                ->waitForText('Download Metadata')
                ->assertNoSmoke()
                ->assertVisible('header img[alt="GFZ Data Services"]');

            $viewports = [
                'desktop' => [1440, 900],
                'mobile' => [393, 852],
            ];

            foreach ($viewports as $viewport => [$width, $height]) {
                $page->resize($width, $height);
                $page->page()->waitForFunction(<<<'JS'
                    () => {
                        const logo = document.querySelector('header img[alt="GFZ Data Services"]');

                        return logo instanceof HTMLImageElement
                            && logo.complete
                            && logo.naturalWidth > 0
                            && logo.naturalHeight > 0;
                    }
                    JS);

                $logoState = $page->script(<<<'JS'
                    () => {
                        const logo = document.querySelector('header img[alt="GFZ Data Services"]');

                        if (!(logo instanceof HTMLImageElement)) {
                            return null;
                        }

                        const rect = logo.getBoundingClientRect();
                        const containerRect = logo.parentElement?.getBoundingClientRect();
                        const style = getComputedStyle(logo);
                        const intrinsicRatio = logo.naturalWidth / logo.naturalHeight;
                        const boxRatio = rect.width / rect.height;
                        const contentWidth = intrinsicRatio > boxRatio
                            ? rect.width
                            : rect.height * intrinsicRatio;
                        const contentHeight = intrinsicRatio > boxRatio
                            ? rect.width / intrinsicRatio
                            : rect.height;

                        return {
                            viewportWidth: window.innerWidth,
                            naturalWidth: logo.naturalWidth,
                            naturalHeight: logo.naturalHeight,
                            containerWidth: containerRect?.width ?? 0,
                            objectFit: style.objectFit,
                            elementWidth: rect.width,
                            elementHeight: rect.height,
                            contentWidth,
                            contentHeight,
                        };
                    }
                    JS);

                expect($logoState, "{$viewport} logo state")->not->toBeNull();
                expect($logoState['naturalWidth'], "{$viewport} natural width")->toBe(1200);
                expect($logoState['naturalHeight'], "{$viewport} natural height")->toBe(240);
                expect($logoState['objectFit'], "{$viewport} object fit")->toBe('contain');
                expect(
                    abs($logoState['elementWidth'] - min($logoState['naturalWidth'], $logoState['containerWidth'])),
                    "{$viewport} width capped by natural or available width",
                )->toBeLessThanOrEqual(1.0);
                expect($logoState['elementWidth'], "{$viewport} element width")
                    ->toBeLessThanOrEqual($logoState['viewportWidth']);
                expect(
                    abs(($logoState['contentWidth'] / $logoState['contentHeight']) - 5),
                    "{$viewport} rendered content ratio",
                )->toBeLessThan(0.01);
            }

            expect($logoState['elementWidth'], 'mobile logo shrinks below its natural width')
                ->toBeLessThan($logoState['naturalWidth']);
        } finally {
            Storage::disk('public')->delete($logoPath);
        }
    });
});

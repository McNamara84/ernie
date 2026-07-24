<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use Database\Seeders\PlaywrightTestSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses()->group('issue-1051', 'browser', 'landing-pages');

describe('Issue 1051 responsive landing page header logo', function (): void {
    it('preserves the five-to-one logo ratio on desktop and mobile viewports', function (): void {
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
                $page->resize($width, $height)->wait(0.2);

                $logoState = $page->script(<<<'JS'
                    () => {
                        const logo = document.querySelector('header img[alt="GFZ Data Services"]');

                        if (!(logo instanceof HTMLImageElement)) {
                            return null;
                        }

                        const rect = logo.getBoundingClientRect();
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
                expect($logoState['elementWidth'], "{$viewport} element width")
                    ->toBeLessThanOrEqual($logoState['viewportWidth']);
                expect(
                    abs(($logoState['contentWidth'] / $logoState['contentHeight']) - 5),
                    "{$viewport} rendered content ratio",
                )->toBeLessThan(0.01);
            }
        } finally {
            Storage::disk('public')->delete($logoPath);
        }
    });
});

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

uses()->group('issue-1051', 'issue-1146', 'issue-1292', 'browser', 'landing-pages');

describe('Issue 1292 compact responsive landing page header', function (): void {
    beforeEach(function (): void {
        app(Vite::class)
            ->useHotFile(storage_path('framework/testing-vite.hot'))
            ->useBuildDirectory('build');
    });

    it('keeps the default header shorter than and flush with the hero on resource and IGSN pages', function (): void {
        /** @var TestCase $this */
        $this->seed(PlaywrightTestSeeder::class);

        $cases = [
            'published resource' => [
                'landingPage' => LandingPage::query()->where('slug', 'playwright-published')->firstOrFail(),
                'title' => 'Playwright: Published Resource',
                'preview' => false,
            ],
            'IGSN preview' => [
                'landingPage' => LandingPage::query()->where('slug', 'playwright-igsn-preview')->firstOrFail(),
                'title' => 'Playwright: IGSN Citation Preview',
                'preview' => true,
            ],
        ];

        foreach ($cases as $caseName => $case) {
            /** @var LandingPage $landingPage */
            $landingPage = $case['landingPage'];
            $browserPath = parse_url($landingPage->public_url, PHP_URL_PATH);

            expect($browserPath, "{$caseName} path")->toBeString()->not->toBe('');

            if ($case['preview']) {
                $browserPath .= '?preview='.urlencode((string) $landingPage->preview_token);
            }

            $page = visit($browserPath)
                ->resize(1440, 900)
                ->waitForText($case['title'])
                ->assertNoSmoke()
                ->assertVisible('[data-testid="landing-page-header-media"]')
                ->assertVisible('[data-testid="landing-page-resource-hero"]');

            if ($case['preview']) {
                $page->assertSee('Preview Mode');
            }

            foreach (['desktop' => [1440, 900], 'mobile' => [393, 852]] as $viewport => [$width, $height]) {
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

                $layout = $page->script(<<<'JS'
                    () => {
                        const outer = document.querySelector('[data-landing-page]');
                        const header = document.querySelector('header[aria-label="GFZ Data Services"]');
                        const media = document.querySelector('[data-testid="landing-page-header-media"]');
                        const hero = document.querySelector('[data-testid="landing-page-resource-hero"]');
                        const logo = header?.querySelector('img[alt="GFZ Data Services"]');

                        if (!(outer instanceof HTMLElement)
                            || !(header instanceof HTMLElement)
                            || !(media instanceof HTMLElement)
                            || !(hero instanceof HTMLElement)
                            || !(logo instanceof HTMLImageElement)) {
                            return null;
                        }

                        const outerRect = outer.getBoundingClientRect();
                        const headerRect = header.getBoundingClientRect();
                        const mediaRect = media.getBoundingClientRect();
                        const heroRect = hero.getBoundingClientRect();

                        return {
                            outerTop: outerRect.top,
                            headerBottom: headerRect.bottom,
                            headerHeight: headerRect.height,
                            mediaRatio: mediaRect.width / mediaRect.height,
                            heroTop: heroRect.top,
                            heroHeight: heroRect.height,
                            heroClassName: hero.className,
                            heroTransform: getComputedStyle(hero).transform,
                            naturalWidth: logo.naturalWidth,
                            naturalHeight: logo.naturalHeight,
                            objectFit: getComputedStyle(logo).objectFit,
                        };
                    }
                    JS);

                expect($layout, "{$caseName} {$viewport} layout")->not->toBeNull();
                expect(abs($layout['outerTop']), "{$caseName} {$viewport} starts at viewport top")->toBeLessThanOrEqual(1.0);
                expect($layout['heroClassName'], "{$caseName} {$viewport} hero is immediately visible")->not->toContain('fade-in-on-scroll');
                expect($layout['heroTransform'], "{$caseName} {$viewport} hero is not translated")->toBe('none');
                expect(
                    abs($layout['headerBottom'] - $layout['heroTop']),
                    "{$caseName} {$viewport} header and hero are flush",
                )->toBeLessThanOrEqual(1.0);
                expect($layout['headerHeight'], "{$caseName} {$viewport} compact header")->toBeLessThan($layout['heroHeight']);
                expect(abs($layout['mediaRatio'] - 9), "{$caseName} {$viewport} media ratio")->toBeLessThan(0.02);
                expect($layout['naturalWidth'], "{$caseName} {$viewport} default logo width")->toBe(1800);
                expect($layout['naturalHeight'], "{$caseName} {$viewport} default logo height")->toBe(200);
                expect($layout['objectFit'], "{$caseName} {$viewport} object fit")->toBe('contain');
            }
        }
    });

    it('contains a stored legacy 5:1 custom logo without cropping or distortion', function (): void {
        /** @var TestCase $this */
        $this->seed(PlaywrightTestSeeder::class);

        $landingPage = LandingPage::query()
            ->where('slug', 'playwright-published')
            ->firstOrFail();
        $logoPath = 'landing-page-logos/browser/issue-1292-legacy-logo.png';
        $logo = UploadedFile::fake()->image('issue-1292-legacy-logo.png', 1200, 240);

        Storage::disk('public')->put($logoPath, $logo->getContent());

        try {
            $template = LandingPageTemplate::factory()->create([
                'name' => 'Issue 1292 Legacy Logo',
                'logo_path' => $logoPath,
                'logo_filename' => $logo->getClientOriginalName(),
            ]);

            $landingPage->update(['landing_page_template_id' => $template->id]);
            app(LandingPageRenderDataCacheService::class)->forget($landingPage);

            $browserPath = parse_url($landingPage->public_url, PHP_URL_PATH);
            expect($browserPath)->toBeString()->not->toBe('');

            $page = visit($browserPath)
                ->resize(1440, 900)
                ->waitForText('Playwright: Published Resource')
                ->assertNoSmoke()
                ->assertVisible('header img[alt="GFZ Data Services"]');

            foreach (['desktop' => [1440, 900], 'mobile' => [393, 852]] as $viewport => [$width, $height]) {
                $page->resize($width, $height);
                $page->page()->waitForFunction(<<<'JS'
                    () => {
                        const logo = document.querySelector('header img[alt="GFZ Data Services"]');

                        return logo instanceof HTMLImageElement
                            && logo.complete
                            && logo.naturalWidth === 1200
                            && logo.naturalHeight === 240;
                    }
                    JS);

                $logoState = $page->script(<<<'JS'
                    () => {
                        const logo = document.querySelector('header img[alt="GFZ Data Services"]');
                        const header = document.querySelector('header[aria-label="GFZ Data Services"]');
                        const hero = document.querySelector('[data-testid="landing-page-resource-hero"]');

                        if (!(logo instanceof HTMLImageElement)
                            || !(header instanceof HTMLElement)
                            || !(hero instanceof HTMLElement)) {
                            return null;
                        }

                        const rect = logo.getBoundingClientRect();
                        const headerRect = header.getBoundingClientRect();
                        const heroRect = hero.getBoundingClientRect();
                        const intrinsicRatio = logo.naturalWidth / logo.naturalHeight;
                        const boxRatio = rect.width / rect.height;
                        const contentWidth = intrinsicRatio > boxRatio ? rect.width : rect.height * intrinsicRatio;
                        const contentHeight = intrinsicRatio > boxRatio ? rect.width / intrinsicRatio : rect.height;

                        return {
                            naturalWidth: logo.naturalWidth,
                            naturalHeight: logo.naturalHeight,
                            objectFit: getComputedStyle(logo).objectFit,
                            boxWidth: rect.width,
                            boxHeight: rect.height,
                            boxRatio,
                            contentWidth,
                            contentHeight,
                            contentRatio: contentWidth / contentHeight,
                            headerBottom: headerRect.bottom,
                            headerHeight: headerRect.height,
                            heroTop: heroRect.top,
                            heroHeight: heroRect.height,
                        };
                    }
                    JS);

                expect($logoState, "{$viewport} legacy logo state")->not->toBeNull();
                expect($logoState['naturalWidth'], "{$viewport} natural width")->toBe(1200);
                expect($logoState['naturalHeight'], "{$viewport} natural height")->toBe(240);
                expect($logoState['objectFit'], "{$viewport} object fit")->toBe('contain');
                expect(abs($logoState['boxRatio'] - 9), "{$viewport} header media box ratio")->toBeLessThan(0.02);
                expect(abs($logoState['contentRatio'] - 5), "{$viewport} rendered content ratio")->toBeLessThan(0.01);
                expect($logoState['contentWidth'], "{$viewport} content contained horizontally")->toBeLessThanOrEqual($logoState['boxWidth']);
                expect($logoState['contentHeight'], "{$viewport} content contained vertically")->toBeLessThanOrEqual($logoState['boxHeight']);
                expect($logoState['contentWidth'], "{$viewport} legacy logo is letterboxed")->toBeLessThan($logoState['boxWidth']);
                expect(
                    abs($logoState['headerBottom'] - $logoState['heroTop']),
                    "{$viewport} legacy header and hero are flush",
                )->toBeLessThanOrEqual(1.0);
                expect($logoState['headerHeight'], "{$viewport} compact legacy header")->toBeLessThan($logoState['heroHeight']);
            }
        } finally {
            Storage::disk('public')->delete($logoPath);
        }
    });
});

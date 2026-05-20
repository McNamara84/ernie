<?php

declare(strict_types=1);

use App\Models\LandingPage;
use Tests\TestCase;

describe('Landing Page Dark Mode Logos', function (): void {
    beforeEach(function (): void {
        /** @var TestCase $this */
        $this->seed(\Database\Seeders\PlaywrightTestSeeder::class);
    });

    it('uses the dedicated DataCite dark-mode asset on the published landing page', function (): void {
        $landingPage = LandingPage::query()
            ->where('slug', 'playwright-published')
            ->first();

        if ($landingPage === null) {
            $this->markTestSkipped('Test landing page not available');
        }

        $browserUrl = parse_url($landingPage->public_url, PHP_URL_PATH);

        if (! is_string($browserUrl) || $browserUrl === '') {
            $this->markTestSkipped('Landing page path not available');
        }

        $page = visit($browserUrl)
            ->inDarkMode()
            ->wait(1)
            ->assertNoSmoke()
            ->waitForText('Download Metadata');

        $logoState = $page->script(<<<'JS'
            () => {
                const logo = document.querySelector('img[alt="DataCite"]');
                const darkSource = logo
                    ?.closest('picture')
                    ?.querySelector('source[media="(prefers-color-scheme: dark)"]');

                return {
                    prefersDark: window.matchMedia('(prefers-color-scheme: dark)').matches,
                    currentSrc: logo?.currentSrc ?? null,
                    darkSrcset: darkSource?.getAttribute('srcset') ?? null,
                };
            }
            JS);

        expect($logoState['prefersDark'])->toBeTrue();
        expect($logoState['darkSrcset'])->toBe('/images/datacite-logo-light.svg');
        expect($logoState['currentSrc'])->toEndWith('/images/datacite-logo-light.svg');
    });
});
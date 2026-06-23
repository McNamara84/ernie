<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\User;
use Database\Seeders\PlaywrightTestSeeder;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

uses()->group('resources', 'browser', 'screenshots');

function resourceForBrowserScreenshot(string $title): Resource
{
    /** @var Resource $resource */
    $resource = Resource::query()
        ->whereHas('titles', fn ($query) => $query->where('value', $title))
        ->firstOrFail();

    return $resource;
}

describe('Resources selection screenshots', function (): void {
    beforeEach(function (): void {
        app(Vite::class)
            ->useHotFile(storage_path('framework/resources-selection-screenshots.hot'))
            ->useBuildDirectory('build');
    });

    it('captures resources page selection states', function (): void {
        if (! filter_var(env('CAPTURE_RESOURCES_SELECTION_SCREENSHOTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set CAPTURE_RESOURCES_SELECTION_SCREENSHOTS=1 to regenerate the resources selection screenshots.');
        }

        /** @var TestCase $this */
        $this->seed(PlaywrightTestSeeder::class);

        $curator = User::query()
            ->where('email', 'curator@example.com')
            ->firstOrFail();

        $firstResource = resourceForBrowserScreenshot('Playwright: Published Resource');
        $secondResource = resourceForBrowserScreenshot('Playwright: QA Resource');

        $this->actingAs($curator);

        $page = visit('/resources')
            ->resize(1440, 1000)
            ->waitForText('Select rows to enable resource actions')
            ->waitForText('Playwright: Published Resource')
            ->waitForText('Playwright: QA Resource')
            ->assertNoSmoke();

        $page->screenshot(true, 'resources-selection-none');

        $page->click(sprintf('[data-testid="resources-row-checkbox-%d"]', $firstResource->id))
            ->waitForText('1 resource selected')
            ->screenshot(true, 'resources-selection-one');

        $page->click(sprintf('[data-testid="resources-row-checkbox-%d"]', $secondResource->id))
            ->waitForText('2 resources selected')
            ->screenshot(true, 'resources-selection-two');
    });
});

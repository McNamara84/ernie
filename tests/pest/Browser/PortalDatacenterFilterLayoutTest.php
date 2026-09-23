<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Foundation\Vite;

uses()->group('browser', 'portal');

beforeEach(function (): void {
    app(Vite::class)
        ->useHotFile(storage_path('framework/testing-vite.hot'))
        ->useBuildDirectory('build');
});

it('shows complete datacenter names and selected values without horizontal clipping', function (
    string $path,
    string $typeSlug,
    int $width,
    int $height
): void {
    $names = [
        'GFZ Helmholtz Centre for Geosciences Scientific Drilling and Sample Management Datacenter',
        'DatacenterWithAnUnbrokenIdentifierABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
    ];
    $type = ResourceType::firstOrCreate(['slug' => $typeSlug], ['name' => $typeSlug, 'is_active' => true]);
    $titleType = TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);

    foreach ($names as $index => $name) {
        $datacenter = Datacenter::factory()->create(['name' => $name]);
        $resource = Resource::factory()->create(['datacenter_id' => $datacenter->id, 'resource_type_id' => $type->id]);
        Title::factory()->create([
            'resource_id' => $resource->id,
            'title_type_id' => $titleType->id,
            'value' => "Datacenter layout fixture {$index}",
        ]);
        LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi]);
    }

    $page = visit($path)
        ->resize($width, $height)
        ->waitForText('Filters')
        ->assertNoSmoke();

    if ($width < 1280) {
        $page->click('[data-testid="portal-filter-drawer-trigger"]')
            ->assertVisible('[data-testid="portal-filter-sidebar"]');
    }

    $page->click('[data-accordion-value="datacenter"] [data-slot="accordion-trigger"]')
        ->waitForText($names[0]);

    $layout = $page->script(<<<'JS'
        () => {
            const sidebar = document.querySelector('[data-testid="portal-filter-sidebar"]');
            const group = sidebar?.querySelector('[role="group"][aria-label="Datacenters"]');
            const viewport = sidebar?.querySelector('[data-slot="scroll-area-viewport"]');
            if (!(sidebar instanceof HTMLElement) || !(group instanceof HTMLElement) || !(viewport instanceof HTMLElement)) return null;

            const right = sidebar.getBoundingClientRect().right;
            const rows = Array.from(group.querySelectorAll('label')).map((row) => {
                const checkbox = row.querySelector('button[aria-label^="Select "]');
                const name = checkbox?.nextElementSibling;
                const count = name?.nextElementSibling;
                if (!(checkbox instanceof HTMLElement) || !(name instanceof HTMLElement) || !(count instanceof HTMLElement)) return null;

                const lineHeight = Number.parseFloat(getComputedStyle(name).lineHeight);
                return {
                    text: name.textContent?.trim(),
                    fontSize: Number.parseFloat(getComputedStyle(name).fontSize),
                    wraps: name.getBoundingClientRect().height > lineHeight * 1.5,
                    rowInside: row.getBoundingClientRect().right <= right + 1,
                    nameInside: name.getBoundingClientRect().right <= right + 1,
                    countInside: count.getBoundingClientRect().right <= right + 1,
                    checkboxInside: checkbox.getBoundingClientRect().right <= right + 1,
                };
            });

            return {
                rows,
                noHorizontalOverflow: viewport.scrollWidth <= viewport.clientWidth + 1,
            };
        }
        JS);

    expect($layout)->not->toBeNull();
    expect($layout['rows'])->toHaveCount(2);
    $renderedNames = array_column($layout['rows'], 'text');
    sort($renderedNames);
    $expectedNames = $names;
    sort($expectedNames);
    expect($renderedNames)->toEqual($expectedNames);
    expect($layout['noHorizontalOverflow'])->toBeTrue();
    foreach ($layout['rows'] as $row) {
        expect($row['fontSize'])->toBe(12);
        expect($row['wraps'])->toBeTrue();
        expect($row['rowInside'])->toBeTrue();
        expect($row['nameInside'])->toBeTrue();
        expect($row['countInside'])->toBeTrue();
        expect($row['checkboxInside'])->toBeTrue();
    }

    $page->click('button[aria-label="Select '.$names[0].'"]')
        ->assertVisible('button[aria-label="Remove '.$names[0].'"]');

    $selected = $page->script(<<<'JS'
        async () => {
            let sidebar;
            let remove;
            let selectedFromUrl = [];
            for (let attempt = 0; attempt < 100; attempt++) {
                sidebar = document.querySelector('[data-testid="portal-filter-sidebar"]');
                remove = sidebar?.querySelector('button[aria-label^="Remove GFZ Helmholtz"]');
                selectedFromUrl = Array.from(new URLSearchParams(location.search))
                    .filter(([key]) => /^datacenter(?:\[\d*\])?$/.test(key))
                    .map(([, value]) => value);
                if (remove instanceof HTMLButtonElement && selectedFromUrl.length === 1) break;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            const chip = remove?.closest('[data-slot="badge"]');
            const name = chip?.querySelector(':scope > span');
            if (!(sidebar instanceof HTMLElement) || !(chip instanceof HTMLElement)
                || !(name instanceof HTMLElement) || !(remove instanceof HTMLButtonElement)) return null;

            const right = sidebar.getBoundingClientRect().right;
            const lineHeight = Number.parseFloat(getComputedStyle(name).lineHeight);
            const state = {
                text: name.textContent?.trim(),
                wraps: name.getBoundingClientRect().height > lineHeight * 1.5,
                chipInside: chip.getBoundingClientRect().right <= right + 1,
                nameInside: name.getBoundingClientRect().right <= right + 1,
                removeInside: remove.getBoundingClientRect().right <= right + 1,
                selectedFromUrl,
            };
            remove.click();

            for (let attempt = 0; attempt < 100; attempt++) {
                const remaining = Array.from(new URLSearchParams(location.search))
                    .filter(([key]) => /^datacenter(?:\[\d*\])?$/.test(key));
                if (remaining.length === 0 && !document.querySelector('button[aria-label^="Remove GFZ Helmholtz"]')) {
                    return { ...state, removed: true };
                }
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return { ...state, removed: false };
        }
        JS);

    expect($selected)->not->toBeNull();
    expect($selected['text'])->toBe($names[0]);
    expect($selected['wraps'])->toBeTrue();
    expect($selected['chipInside'])->toBeTrue();
    expect($selected['nameInside'])->toBeTrue();
    expect($selected['removeInside'])->toBeTrue();
    expect($selected['selectedFromUrl'])->toBe([$names[0]]);
    expect($selected['removed'])->toBeTrue();
    $page->assertNoSmoke();
})->with([
    'Data Portal desktop' => ['/doi-search', 'dataset', 1440, 900],
    'Data Portal mobile' => ['/doi-search', 'dataset', 393, 852],
    'IGSN Portal desktop' => ['/igsn-search', 'physical-object', 1440, 900],
    'IGSN Portal mobile' => ['/igsn-search', 'physical-object', 393, 852],
]);

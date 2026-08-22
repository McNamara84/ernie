<?php

declare(strict_types=1);

use App\Models\AlternateIdentifier;
use App\Models\Description;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use Illuminate\Foundation\Vite;

uses()->group('browser', 'screenshots', 'issue-1127', 'temporary');

beforeEach(function (): void {
    app(Vite::class)
        ->useHotFile(storage_path('framework/issue-1127-screenshots.hot'))
        ->useBuildDirectory('build');
});

/**
 * Temporary visual-review fixture for Issue 1127.
 *
 * @return array{root: LandingPage, core_a: LandingPage, fragment_a1: LandingPage, fragment_a2: LandingPage}
 */
function issue1127BrowserFamily(): array
{
    $physicalObjectType = ResourceType::query()->firstOrCreate(
        ['slug' => 'physical-object'],
        [
            'name' => 'Physical Object',
            'description' => 'A physical sample.',
            'is_active' => true,
            'is_elmo_active' => true,
        ],
    );
    LandingPageTemplate::ensureIgsnDefaultTemplateExists();

    $createMember = function (
        string $handle,
        string $name,
        string $title,
        string $sampleType,
        ?Resource $parent = null,
        ?bool $published = true,
    ) use ($physicalObjectType): array {
        $resource = Resource::factory()->create([
            'doi' => '10.60510/'.strtolower($handle),
            'identifier_type' => 'IGSN',
            'resource_type_id' => $physicalObjectType->id,
            'publication_year' => 2026,
        ]);

        Title::factory()->create([
            'resource_id' => $resource->id,
            'value' => $title,
        ]);
        AlternateIdentifier::query()->create([
            'resource_id' => $resource->id,
            'value' => $name,
            'type' => 'Local accession number',
            'position' => 0,
        ]);
        Description::factory()->abstract()->create([
            'resource_id' => $resource->id,
            'value' => "Temporary Issue 1127 browser fixture for {$name}. It demonstrates navigation through a locally known IGSN sample family.",
        ]);
        ResourceCreator::factory()->create([
            'resource_id' => $resource->id,
            'position' => 1,
        ]);
        IgsnMetadata::query()->create([
            'resource_id' => $resource->id,
            'parent_resource_id' => $parent?->id,
            'sample_type' => $sampleType,
            'material' => 'Basalt',
            'collection_method' => 'Drilling',
            'collection_method_description' => 'Recovered during expedition M127.',
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $landingPage = null;
        if ($published !== null) {
            $factory = LandingPage::factory()
                ->withDoi((string) $resource->doi)
                ->state([
                    'resource_id' => $resource->id,
                    'slug' => strtolower($handle),
                    'template' => LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG,
                    'downloads_unavailable' => true,
                ]);

            $landingPage = $published
                ? $factory->published()->create()
                : $factory->draft()->create();
        }

        return ['resource' => $resource, 'landingPage' => $landingPage];
    };

    $root = $createMember(
        'GFZISSUE1127ROOT',
        'Expedition M127 — Station 04',
        'IGSN family: Expedition M127, Station 04',
        'Hole',
    );
    $coreA = $createMember(
        'GFZISSUE1127A',
        'Core segment A',
        'Basalt drill core segment A from Station 04',
        'Core',
        $root['resource'],
    );
    $fragmentA1 = $createMember(
        'GFZISSUE1127A1',
        'Thin section A1',
        'Thin section A1 prepared from core segment A',
        'Individual Sample',
        $coreA['resource'],
    );
    $fragmentA2 = $createMember(
        'GFZISSUE1127A2',
        'Thin section A2 — draft landing page',
        'Draft thin section A2 prepared from core segment A',
        'Individual Sample',
        $coreA['resource'],
        false,
    );
    $createMember(
        'GFZISSUE1127B',
        'Core segment B — no landing page',
        'Basalt drill core segment B from Station 04',
        'Core',
        $root['resource'],
        null,
    );

    return [
        'root' => $root['landingPage'],
        'core_a' => $coreA['landingPage'],
        'fragment_a1' => $fragmentA1['landingPage'],
        'fragment_a2' => $fragmentA2['landingPage'],
    ];
}

function issue1127BrowserUrl(LandingPage $landingPage, bool $preview = false): string
{
    $url = $preview ? $landingPage->preview_url : $landingPage->public_url;
    $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;
    $query = is_string($url) ? parse_url($url, PHP_URL_QUERY) : null;

    if (! is_string($path) || $path === '') {
        throw new RuntimeException('Landing page path is unavailable.');
    }

    return is_string($query) && $query !== '' ? "{$path}?{$query}" : $path;
}

it('captures the complete family from its published root on desktop', function (): void {
    $family = issue1127BrowserFamily();

    visit(issue1127BrowserUrl($family['root']))
        ->resize(1440, 1200)
        ->assertNoSmoke()
        ->waitForText('Sample Family')
        ->waitForText('Complete sampling hierarchy known to ERNIE (5 samples)')
        ->waitForText('Core segment B — no landing page')
        ->screenshot(true, 'issue-1127-sample-family-root-desktop');
});

it('captures an intermediate member with published, draft, and missing landing pages', function (): void {
    $family = issue1127BrowserFamily();

    visit(issue1127BrowserUrl($family['core_a']))
        ->resize(1440, 1200)
        ->assertNoSmoke()
        ->waitForText('Sample Family')
        ->waitForText('Thin section A2 — draft landing page')
        ->waitForText('Current sample')
        ->screenshot(true, 'issue-1127-sample-family-intermediate-mixed-states');
});

it('captures a published leaf as the current member in dark mode', function (): void {
    $family = issue1127BrowserFamily();

    visit(issue1127BrowserUrl($family['fragment_a1']))
        ->inDarkMode()
        ->resize(1440, 1200)
        ->assertNoSmoke()
        ->waitForText('Sample Family')
        ->waitForText('Thin section A1')
        ->waitForText('Current sample')
        ->screenshot(true, 'issue-1127-sample-family-leaf-dark');
});

it('captures a draft leaf preview on a mobile viewport', function (): void {
    $family = issue1127BrowserFamily();

    $page = visit(issue1127BrowserUrl($family['fragment_a2'], preview: true))->on()
        ->mobile()
        ->assertNoSmoke()
        ->waitForText('Preview Mode')
        ->waitForText('Sample Family')
        ->waitForText('Current sample');

    $page->screenshot(false, 'issue-1127-sample-family-draft-preview-mobile-top');
    $page->script(<<<'JS'
        () => document
            .querySelector('[aria-labelledby="heading-sample-family"]')
            ?.scrollIntoView({ block: 'start' })
        JS);
    $page->wait(1)
        ->screenshot(false, 'issue-1127-sample-family-draft-preview-mobile-family');
});

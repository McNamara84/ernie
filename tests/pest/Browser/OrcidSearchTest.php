<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\User;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Api\PendingAwaitablePage;

uses()->group('browser', 'orcid');

const ORCID_SEARCH_INPUT = '[role="dialog"] input[type="search"]';
const ORCID_SEARCH_STATUS = '[role="dialog"] [role="status"]';
const ORCID_SEARCH_API = 'pub.orcid.org/v3.0/expanded-search/*';
const ORCID_PROFILE_ONE = '0000-0002-1825-0097';
const ORCID_PROFILE_TWO = '0000-0001-5109-3700';

function orcidBrowserMatches(): array
{
    return ['num-found' => 2, 'expanded-result' => [
        ['orcid-id' => ORCID_PROFILE_ONE, 'given-names' => 'Holger', 'family-names' => 'Ehrmann', 'institution-name' => ['GFZ Potsdam']],
        ['orcid-id' => ORCID_PROFILE_TWO, 'given-names' => 'Holger', 'family-names' => 'Ehrmann', 'institution-name' => ['GFZ', 'Example University']],
    ]];
}

beforeEach(function (): void {
    app(Vite::class)->useHotFile(storage_path('framework/testing-vite.hot'))
        ->createAssetPathsUsing(static fn (string $path): string => '/'.ltrim($path, '/'));
    $this->actingAs(User::factory()->curator()->create());
    Http::preventStrayRequests();
    Http::fake(['pub.orcid.org/v3.0/0000-*' => Http::response([
        'person' => ['name' => ['given-names' => ['value' => 'Holger'], 'family-name' => ['value' => 'Ehrmann']]],
    ])]);
    $this->searches = collect();
    Event::listen(RouteMatched::class, function (RouteMatched $event): void {
        if ($event->request->is('api/v1/orcid/search')) {
            $this->searches->push($event->request->query());
        }
    });
});

function openOrcidBrowserDialog(PendingAwaitablePage $page, string $kind, int $index = 0): string
{
    $section = '[data-slot="accordion-trigger"]:has-text("'.ucfirst($kind).'s")';
    $page->assertVisible($section)->assertMissing('@editor-loading-modal');
    if ($page->attribute($section, 'aria-expanded') !== 'true') {
        $page->click($section);
    }
    $field = '[data-testid="'.$kind.'-'.$index.'-orcid-field"]';
    $page->click('[aria-label="Add '.($index === 0 ? 'first' : 'another').' '.$kind.'"]')
        ->click($field.' [aria-label="Search for ORCID"]')
        ->assertVisible(ORCID_SEARCH_INPUT.':focus');

    return $field;
}

/** Hold already received JSON so the browser can deliver even an aborted search out of order. */
function holdOrcidBrowserReplies(PendingAwaitablePage $page, string ...$queries): void
{
    $terms = json_encode($queries, JSON_THROW_ON_ERROR);
    $page->script(<<<JS
        () => {
            const held = new Set($terms);
            const readJson = Response.prototype.json;
            window.orcidReplies = new Map();
            Response.prototype.json = async function () {
                const body = await readJson.call(this);
                const url = new URL(this.url, location.href);
                const term = url.searchParams.get('q');
                if (url.pathname === '/api/v1/orcid/search' && held.has(term)) {
                    await new Promise(resolve => {
                        window.orcidReplies.set(term, async () => {
                            resolve();
                            // Assert against the rendered UI after the late response has had a chance to update it.
                            await new Promise(requestAnimationFrame);
                            await new Promise(requestAnimationFrame);
                        });
                    });
                }
                return body;
            };
        }
        JS);
}

it('waits for explicit submission during typing pauses and selects the intended author on an existing resource', function (): void {
    Http::fake([ORCID_SEARCH_API => Http::response(orcidBrowserMatches())]);
    $resource = Resource::factory()->create();
    ResourceCreator::factory()->for($resource)->create();
    $page = visit('/editor?resourceId='.$resource->id);
    $field = openOrcidBrowserDialog($page, 'author', 1);
    holdOrcidBrowserReplies($page, 'Ehrmann, Holger, GFZ');

    // The pauses model a person thinking and correcting a typo. Response timing is controlled separately.
    $page->typeSlowly(ORCID_SEARCH_INPUT, 'Ehrmann,', 90)->wait(0.65)
        ->typeSlowly(ORCID_SEARCH_INPUT, ' Holget', 85)->wait(0.55)
        ->keys(ORCID_SEARCH_INPUT, 'Backspace')->typeSlowly(ORCID_SEARCH_INPUT, 'r, GFZ', 105)
        ->assertValue(ORCID_SEARCH_INPUT, 'Ehrmann, Holger, GFZ');
    expect($this->searches)->toBeEmpty();
    $page->keys(ORCID_SEARCH_INPUT, 'Enter')->assertSeeIn(ORCID_SEARCH_STATUS, 'Searching ORCID')
        ->assertScript("window.orcidReplies.has('Ehrmann, Holger, GFZ')")
        ->keys(ORCID_SEARCH_INPUT, 'Enter');
    $page->script("() => window.orcidReplies.get('Ehrmann, Holger, GFZ')()");
    $first = '[role="dialog"] button:has-text("GFZ Potsdam")';
    $second = '[role="dialog"] button:has-text("Example University")';
    $page->assertVisible($second)->assertValue($field.' input', '');
    expect($this->searches->all())->toEqual([['q' => 'Ehrmann, Holger, GFZ', 'limit' => '20', 'mode' => 'person']]);
    Http::assertSentCount(1);
    $page->keys(ORCID_SEARCH_INPUT, 'ArrowDown')->assertVisible($first.':focus')
        ->keys($first, 'ArrowDown')->assertVisible($second.':focus')->keys($second, 'Enter')
        ->assertMissing(ORCID_SEARCH_INPUT)->assertValue($field.' input', ORCID_PROFILE_TWO)
        ->assertVisible($field.' [aria-label="Search for ORCID"]:focus')
        ->assertSeeIn($field.' [id$="-orcid-status"]', 'ORCID verified');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/'.ORCID_PROFILE_TWO));
    Http::assertSentCount(2);
});

it('opens contributor profiles by keyboard and mouse before explicitly choosing a result', function (): void {
    Http::fake([ORCID_SEARCH_API => Http::response(orcidBrowserMatches())]);
    // The local stand-in records an actual navigation from each new tab and then closes it.
    Route::get('/'.ORCID_PROFILE_ONE, static fn () => response(<<<'HTML'
        <script>
            localStorage.setItem('orcid-profile-visits', String(Number(localStorage.getItem('orcid-profile-visits')) + 1));
            window.close();
        </script>
        HTML));
    $page = visit('/editor');
    $field = openOrcidBrowserDialog($page, 'contributor');
    $choice = '[role="dialog"] button:has-text("GFZ Potsdam")';
    $link = '[aria-label="View '.ORCID_PROFILE_ONE.' on ORCID.org"]';
    $page->typeSlowly(ORCID_SEARCH_INPUT, 'Ehrmann, Holger', 70)->click('[role="search"] button')
        ->assertVisible($choice)->assertAttribute($link, 'href', 'https://orcid.org/'.ORCID_PROFILE_ONE)
        ->assertAttribute($link, 'target', '_blank');
    $page->script("() => { const link = document.querySelector('$link'); link.href = new URL(link.href).pathname; }");
    $page->keys(ORCID_SEARCH_INPUT, 'ArrowDown')->assertVisible($choice.':focus')
        ->keys($choice, 'Tab')->assertVisible($link.':focus')->keys($link, 'Enter')
        ->assertScript("Number(localStorage.getItem('orcid-profile-visits'))", 1)
        ->assertVisible(ORCID_SEARCH_INPUT)->assertValue($field.' input', '')
        ->click($link)->assertScript("Number(localStorage.getItem('orcid-profile-visits'))", 2)
        ->assertVisible(ORCID_SEARCH_INPUT)->assertValue($field.' input', '');
    Http::assertSentCount(1);
    $page->click($choice)->assertMissing(ORCID_SEARCH_INPUT)->assertValue($field.' input', ORCID_PROFILE_ONE)
        ->assertSeeIn($field.' [id$="-orcid-status"]', 'ORCID verified');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/'.ORCID_PROFILE_ONE));
    Http::assertSentCount(2);
});

it('ignores a late match after editing and a late failure after reopening the dialog', function (): void {
    Http::fake([ORCID_SEARCH_API => Http::sequence()->push(orcidBrowserMatches())->push(['num-found' => 0])->pushStatus(503)]);
    $page = visit('/editor');
    $field = openOrcidBrowserDialog($page, 'contributor');
    holdOrcidBrowserReplies($page, 'Taylor', 'Curie');
    $page->fill(ORCID_SEARCH_INPUT, 'Taylor')->keys(ORCID_SEARCH_INPUT, 'Enter')
        ->assertScript("window.orcidReplies.has('Taylor')")
        ->fill(ORCID_SEARCH_INPUT, 'Ehrmann')->keys(ORCID_SEARCH_INPUT, 'Enter')
        ->assertSeeIn(ORCID_SEARCH_STATUS, 'No ORCID records found');
    $page->script("() => window.orcidReplies.get('Taylor')()");
    $page->assertSeeIn(ORCID_SEARCH_STATUS, 'No ORCID records found')->assertMissing('[aria-label="ORCID search results"]')
        ->fill(ORCID_SEARCH_INPUT, 'Curie')->keys(ORCID_SEARCH_INPUT, 'Enter')->assertScript("window.orcidReplies.has('Curie')")
        ->keys(ORCID_SEARCH_INPUT, 'Escape')->assertVisible($field.' [aria-label="Search for ORCID"]:focus')
        ->click($field.' [aria-label="Search for ORCID"]');
    $page->script("() => window.orcidReplies.get('Curie')()");
    $page->assertValue(ORCID_SEARCH_INPUT, '')->assertSeeIn(ORCID_SEARCH_STATUS, 'at least two characters')->assertMissing('Try again');
    expect($this->searches->pluck('q')->all())->toBe(['Taylor', 'Ehrmann', 'Curie']);
});

it('distinguishes an empty search from rate limiting and retries the same name', function (): void {
    Http::fake([ORCID_SEARCH_API => Http::sequence()->push(['num-found' => 0])->pushStatus(429)->push(orcidBrowserMatches())]);
    $page = visit('/editor');
    openOrcidBrowserDialog($page, 'contributor');
    $page->fill(ORCID_SEARCH_INPUT, 'Li')->keys(ORCID_SEARCH_INPUT, 'Enter')->assertSeeIn(ORCID_SEARCH_STATUS, 'No ORCID records found')
        ->fill(ORCID_SEARCH_INPUT, 'Ehrmann, Holger')->keys(ORCID_SEARCH_INPUT, 'Enter')->assertSeeIn(ORCID_SEARCH_STATUS, 'too many requests')
        ->click('Try again')->assertVisible('[role="dialog"] button:has-text("GFZ Potsdam")')->assertValue(ORCID_SEARCH_INPUT, 'Ehrmann, Holger');
    expect($this->searches->pluck('q')->all())->toBe(['Li', 'Ehrmann, Holger', 'Ehrmann, Holger']);
});

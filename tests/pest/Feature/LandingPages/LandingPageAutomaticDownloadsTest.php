<?php

use App\Models\LandingPage;
use App\Models\LandingPageDailyStatistic;
use App\Models\LandingPageDomain;
use App\Models\Resource;
use App\Models\User;
use App\Services\LegacyLandingPageImportService;
use Illuminate\Support\Facades\Session;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->actingAs(User::factory()->curator()->create());
    $this->resource = Resource::factory()->create();
    $this->endpoint = '/resources/'.$this->resource->id.'/landing-page';
});

it('derives empty and populated create responses without saving a suppression flag', function (?string $url, bool $unavailable) {
    $this->postJson($this->endpoint, ['template' => 'default_gfz', 'ftp_url' => $url])
        ->assertCreated()->assertJsonPath('landing_page.downloads_unavailable', $unavailable)
        ->assertJsonPath('landing_page.download_activation_required', false);
    expect($this->resource->fresh()->landingPage->downloads_unavailable)->toBeFalse();
})->with([[null, true], ['https://example.org/data.zip', false], ['   ', true]]);

it('rejects the obsolete flag and client-controlled protection on every write endpoint', function (string $method, string $suffix, string $field) {
    if ($method === 'putJson') {
        LandingPage::factory()->create(['resource_id' => $this->resource->id]);
    }
    $this->{$method}($this->endpoint.$suffix, ['template' => 'default_gfz', $field => false])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['postJson', '', 'downloads_unavailable'], ['putJson', '', 'downloads_unavailable'],
    ['postJson', '/preview', 'downloads_unavailable'], ['postJson', '', 'download_activation_required'],
    ['putJson', '', 'download_activation_required'], ['postJson', '/preview', 'download_activation_required'],
]);

it('automatically offers the first URL despite an empty historical flag', function () {
    $page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'ftp_url' => null, 'downloads_unavailable' => true]);
    $this->putJson($this->endpoint, ['ftp_url' => 'https://example.org/new.zip'])
        ->assertOk()->assertJsonPath('landing_page.downloads_unavailable', false)
        ->assertJsonPath('landing_page.download_activation_required', false);
    expect($page->fresh()->downloads_unavailable)->toBeFalse();
});

it('preserves protected values across edits and allows explicit repeatable activation', function () {
    $page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'ftp_url' => 'https://example.org/old.zip', 'downloads_unavailable' => true]);
    $this->putJson($this->endpoint, ['ftp_url' => 'https://example.org/edited.zip', 'primary_download_label' => 'Edited'])
        ->assertOk()->assertJsonPath('landing_page.download_activation_required', true);
    expect($page->fresh()->downloads_unavailable)->toBeTrue();
    foreach ([1, 2] as $attempt) {
        $this->putJson($this->endpoint, ['activate_downloads' => true])
            ->assertOk()->assertJsonPath('landing_page.downloads_unavailable', false)
            ->assertJsonPath('landing_page.download_activation_required', false);
    }
});

it('returns to automatic requests after removing the last retained URL', function () {
    $page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'ftp_url' => 'https://example.org/hidden.zip', 'downloads_unavailable' => true]);
    $this->putJson($this->endpoint, ['ftp_url' => null])->assertOk()
        ->assertJsonPath('landing_page.downloads_unavailable', true)
        ->assertJsonPath('landing_page.download_activation_required', false);
    expect($page->fresh()->downloads_unavailable)->toBeFalse();
});

it('does not treat additional download links as a primary download source', function () {
    $this->postJson($this->endpoint, ['template' => 'default_gfz', 'links' => [
        ['url' => 'https://example.org/extra.zip', 'label' => 'Extra', 'kind' => 'download', 'position' => 0],
    ]])->assertCreated()->assertJsonPath('landing_page.downloads_unavailable', true);
});

it('preserves every imported file during partial preview edits and never persists activation', function () {
    $page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'ftp_url' => null]);
    $file = $page->files()->create(['url' => 'https://example.org/one.zip', 'position' => 0]);
    $page->files()->create(['url' => 'https://example.org/two.zip', 'position' => 1]);
    $page->update(['downloads_unavailable' => true]);

    $this->postJson($this->endpoint.'/preview', ['template' => 'default_gfz', 'files' => [['id' => $file->id, 'label' => 'Preview']]])->assertCreated();
    $this->get($this->endpoint.'/preview')->assertOk()->assertInertia(fn (AssertableInertia $view) => $view
        ->where('landingPage.downloads_unavailable', true)->where('landingPage.files', []));

    $this->postJson($this->endpoint.'/preview', ['template' => 'default_gfz', 'activate_downloads' => true])->assertCreated();
    $this->get($this->endpoint.'/preview')->assertOk()->assertInertia(fn (AssertableInertia $view) => $view
        ->where('landingPage.downloads_unavailable', false)->has('landingPage.files', 2));
    expect($page->fresh()->downloads_unavailable)->toBeTrue()->and($file->fresh()->label)->toBeNull();
});

it('does not trust an activation value from a legacy preview session', function () {
    LandingPage::factory()->create(['resource_id' => $this->resource->id, 'ftp_url' => 'https://example.org/hidden.zip', 'downloads_unavailable' => true]);
    Session::put('landing_page_preview.'.$this->resource->id, [
        'template' => 'default_gfz', 'ftp_url' => 'https://example.org/hidden.zip',
        'downloads_unavailable' => false, 'activate_downloads' => true,
    ]);
    $this->get($this->endpoint.'/preview')->assertOk()->assertInertia(fn (AssertableInertia $view) => $view
        ->where('landingPage.downloads_unavailable', true)->where('landingPage.ftp_url', null));
});

it('preserves suppression while importing more legacy file information', function () {
    $page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'ftp_url' => 'https://example.org/hidden.zip', 'downloads_unavailable' => true]);
    app(LegacyLandingPageImportService::class)->syncMissingFileEntries($this->resource, [
        ['url' => 'https://example.org/hidden.zip', 'label' => 'Imported'],
    ], false);
    expect($page->fresh()->downloads_unavailable)->toBeTrue();
});

it('keeps imported files protected through external and internal template switches', function () {
    $page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'ftp_url' => null]);
    $page->files()->create(['url' => 'https://example.org/hidden.zip', 'position' => 0]);
    $page->update(['downloads_unavailable' => true]);
    $domain = LandingPageDomain::factory()->create();
    $this->putJson($this->endpoint, ['template' => 'external', 'external_domain_id' => $domain->id, 'external_path' => 'dataset'])
        ->assertOk()->assertJsonPath('landing_page.downloads_unavailable', false);
    $this->putJson($this->endpoint, ['template' => 'default_gfz'])->assertOk()
        ->assertJsonPath('landing_page.download_activation_required', true);
});

it('blocks tracked downloads while protected without recording a click', function () {
    $page = LandingPage::factory()->published()->create(['resource_id' => $this->resource->id, 'ftp_url' => 'https://example.org/hidden.zip']);
    $file = $page->files()->create(['url' => 'https://example.org/file.zip', 'position' => 0]);
    $page->update(['downloads_unavailable' => true]);
    $this->get(route('landing-page.download.primary', $page))->assertNotFound();
    $this->get(route('landing-page.download.file', [$page, $file]))->assertNotFound();
    expect(LandingPageDailyStatistic::where('landing_page_id', $page->id)->sum('file_download_click_count'))->toBe(0);
});

it('ignores historical placeholder files in public and draft previews without hiding a usable primary URL', function (string $placeholder) {
    $page = LandingPage::factory()->published()->create([
        'resource_id' => $this->resource->id,
        'ftp_url' => 'https://example.org/usable.zip',
    ]);
    $file = $page->files()->create(['url' => $placeholder, 'position' => 0]);
    $this->get($page->public_url)->assertOk()->assertInertia(fn (AssertableInertia $view) => $view
        ->where('landingPage.downloads_unavailable', false)
        ->where('landingPage.ftp_url', 'https://example.org/usable.zip')
        ->where('landingPage.files', []));
    $this->postJson($this->endpoint.'/preview', ['template' => 'default_gfz', 'ftp_url' => $page->ftp_url])->assertCreated();
    $this->get($this->endpoint.'/preview')->assertOk()->assertInertia(fn (AssertableInertia $view) => $view
        ->where('landingPage.downloads_unavailable', false)
        ->where('landingPage.ftp_url', 'https://example.org/usable.zip')
        ->where('landingPage.files', []));
    $this->get(route('landing-page.download.file', [$page, $file]))->assertNotFound();
    expect($file->fresh()->url)->toBe($placeholder);
})->with(['#', '   ', 'not-a-url']);

it('automatically activates the first imported file on an otherwise empty historical page', function () {
    $page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'ftp_url' => null, 'downloads_unavailable' => true]);
    $page->files()->create(['url' => 'https://example.org/import.zip', 'position' => 0]);
    $this->getJson($this->endpoint)->assertOk()->assertJsonPath('landing_page.downloads_unavailable', false)
        ->assertJsonPath('landing_page.download_activation_required', false);
    expect($page->fresh()->downloads_unavailable)->toBeFalse();
});

it('rejects activation on an external page even without an explicit template field', function () {
    LandingPage::factory()->external()->create(['resource_id' => $this->resource->id]);
    $this->putJson($this->endpoint, ['activate_downloads' => true])->assertUnprocessable()->assertJsonValidationErrors('activate_downloads');
});

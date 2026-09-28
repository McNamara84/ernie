<?php

use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Setting;
use App\Models\TitleType;
use App\Models\User;
use App\Services\LandingPageDownloadUrlSuggestionService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->payload = [
        'resourceTypes' => [['id' => ResourceType::factory()->create()->id, 'name' => 'Dataset', 'active' => true, 'elmo_active' => true]],
        'titleTypes' => [['id' => TitleType::factory()->create()->id, 'name' => 'Main', 'slug' => 'main', 'active' => true, 'elmo_active' => true]],
        'licenses' => [['id' => Right::factory()->create()->id, 'active' => true, 'elmo_active' => true, 'excluded_resource_type_ids' => []]],
        'languages' => [['id' => Language::factory()->create()->id, 'active' => true, 'elmo_active' => true]],
        'dateTypes' => [['id' => DateType::factory()->create()->id, 'active' => true]],
        'descriptionTypes' => [['id' => DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true, 'is_elmo_active' => true])->id, 'active' => true, 'elmo_active' => true]],
    ];
});

it('saves arbitrary prefixes and returns their order ahead of observed domains', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    LandingPage::factory()->create(['ftp_url' => 'https://popular.example.org/file.zip']);
    $order = ['https://datapub.gfz.de/download', 'https://another.example.org/Archive'];
    $this->post('/settings', [...$this->payload, 'downloadUrlSuggestionOrder' => $order])->assertSessionHasNoErrors()->assertRedirect();
    expect(app(LandingPageDownloadUrlSuggestionService::class)->order())->toBe($order);
    $this->getJson('/api/landing-page-download-url-suggestions')->assertOk()
        ->assertJsonPath('suggestions.domains.0.value', $order[0])
        ->assertJsonPath('suggestions.domains.1.value', $order[1])
        ->assertJsonPath('suggestions.domains.2.value', 'https://popular.example.org/');
})->with(['admin', 'group_leader']);

it('preserves omitted ordering and resets it with an explicitly empty list', function () {
    Setting::create(['key' => LandingPageDownloadUrlSuggestionService::SETTING_KEY, 'value' => '["https://example.org/download"]']);
    $this->post('/settings', $this->payload)->assertSessionHasNoErrors();
    expect(app(LandingPageDownloadUrlSuggestionService::class)->order())->toBe(['https://example.org/download']);
    $this->post('/settings', [...$this->payload, 'downloadUrlSuggestionOrder' => []])->assertSessionHasNoErrors();
    expect(app(LandingPageDownloadUrlSuggestionService::class)->order())->toBe([]);
});

it('rejects invalid, duplicate or oversized configurations', function (mixed $order, string $field) {
    $this->post('/settings', [...$this->payload, 'downloadUrlSuggestionOrder' => $order])->assertSessionHasErrors($field);
    expect(Setting::where('key', LandingPageDownloadUrlSuggestionService::SETTING_KEY)->exists())->toBeFalse();
})->with([
    [['javascript:alert(1)'], 'downloadUrlSuggestionOrder.0'],
    [['not-a-url'], 'downloadUrlSuggestionOrder.0'],
    [['https://EXAMPLE.org/download', 'https://example.org/download'], 'downloadUrlSuggestionOrder.0'],
    [[null], 'downloadUrlSuggestionOrder.0'],
    ['wrong-shape', 'downloadUrlSuggestionOrder'],
    [[str_repeat('a', 2049)], 'downloadUrlSuggestionOrder.0'],
    [array_map(fn ($i) => 'https://example.org/'.$i.str_repeat('a', 1800), range(1, 40)), 'downloadUrlSuggestionOrder'],
]);

it('denies settings writes to curators while keeping suggestion reads available', function () {
    $this->actingAs(User::factory()->curator()->create());
    $this->post('/settings', [...$this->payload, 'downloadUrlSuggestionOrder' => ['https://example.org/download']])->assertForbidden();
    $this->getJson('/api/landing-page-download-url-suggestions')->assertOk();
});

it('limits only after merging configured prefixes and exposes the full list to settings', function () {
    for ($i = 0; $i < 23; $i++) {
        LandingPage::factory()->create(['ftp_url' => "https://host{$i}.example.org/file"]);
    }
    Setting::create(['key' => LandingPageDownloadUrlSuggestionService::SETTING_KEY, 'value' => '["https://unused.example.org/download"]']);
    $service = app(LandingPageDownloadUrlSuggestionService::class);
    expect($service->suggestions()['domains'])->toHaveCount(20)
        ->and($service->suggestions()['domains'][0]['value'])->toBe('https://unused.example.org/download')
        ->and($service->suggestions(false)['domains'])->toHaveCount(24);
});

it('counts prefix usage at path boundaries and deduplicates normalized domains', function () {
    foreach (['download', 'download/one.zip', 'download?file=2', 'download-other'] as $path) {
        LandingPage::factory()->create(['ftp_url' => 'https://example.org/'.$path]);
    }
    Setting::create(['key' => LandingPageDownloadUrlSuggestionService::SETTING_KEY,
        'value' => '["https://example.org/download","https://EXAMPLE.org/"]']);
    $domains = app(LandingPageDownloadUrlSuggestionService::class)->suggestions()['domains'];
    expect($domains)->toHaveCount(2)->and($domains[0]['usage_count'])->toBe(3)->and($domains[1]['usage_count'])->toBe(4);
});

it('invalidates cached suggestions when imported files change inside a committed transaction', function () {
    $page = LandingPage::factory()->create(['ftp_url' => null]);
    $service = app(LandingPageDownloadUrlSuggestionService::class);
    expect($service->suggestions()['domains'])->toBe([]);
    $file = DB::transaction(fn () => $page->files()->create(['url' => 'https://new.example.org/file.zip', 'position' => 0]));
    expect($service->suggestions()['domains'][0]['value'])->toBe('https://new.example.org/');
    DB::transaction(fn () => $file->delete());
    expect($service->suggestions()['domains'])->toBe([]);
});

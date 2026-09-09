<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\PublicTrafficHourlyStatistic;
use App\Models\Resource;
use App\Models\Title;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-09 12:15:00 UTC');
    Cache::flush();
    config()->set([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'public_traffic.enabled' => true,
        'bot_protection.enabled' => true,
        'bot_protection.ai_user_agents' => ['GPTBot'],
        'bot_protection.crawler_user_agents' => ['Googlebot'],
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('records published landing pages and both portals with cross-surface deduplication', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.5880/public.traffic.001']);
    $domain = LandingPageDomain::factory()->withDomain('https://example.org/')->create();
    $landingPage = LandingPage::factory()->published()->external()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => '10.5880/public.traffic.001',
        'slug' => 'traffic-test',
        'external_domain_id' => $domain->id,
        'external_path' => 'dataset/traffic-test',
    ]);

    $client = $this->withHeader('User-Agent', 'Mozilla/5.0 Traffic Integration Browser');
    $client->get($landingPage->getPublicPath())->assertStatus(301);
    $client->get('/doi-search')->assertOk();
    $client->get('/igsn-search')->assertOk();

    $row = PublicTrafficHourlyStatistic::query()->sole();
    expect($row->landing_page_unique_visitor_count)->toBe(1)
        ->and($row->portal_unique_visitor_count)->toBe(1)
        ->and($row->combined_unique_visitor_count)->toBe(1);
});

it('records a successfully rendered internal published landing page', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.5880/public.traffic.002']);
    Title::factory()->for($resource)->create(['value' => 'Public traffic integration']);
    $landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => '10.5880/public.traffic.002',
        'slug' => 'internal-traffic-test',
        'template' => 'default_gfz',
    ]);

    $this->withHeader('User-Agent', 'Mozilla/5.0 Traffic Integration Browser')
        ->get($landingPage->getPublicPath())
        ->assertOk();

    expect(PublicTrafficHourlyStatistic::query()->sole()->landing_page_unique_visitor_count)->toBe(1);
});

it('excludes draft previews, authenticated portal users, and crawler requests', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.5880/public.traffic.003']);
    $domain = LandingPageDomain::factory()->create();
    $landingPage = LandingPage::factory()->draft()->external()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => '10.5880/public.traffic.003',
        'slug' => 'traffic-preview-test',
        'external_domain_id' => $domain->id,
        'external_path' => 'preview',
    ]);

    $this->withHeader('User-Agent', 'Mozilla/5.0 Traffic Integration Browser')
        ->get($landingPage->getPublicPath().'?preview='.$landingPage->preview_token)
        ->assertStatus(302);
    $this->actingAs(User::factory()->admin()->create())
        ->withHeader('User-Agent', 'Mozilla/5.0 Traffic Integration Browser')
        ->get('/doi-search')
        ->assertOk();
    auth()->logout();
    $this->withHeader('User-Agent', 'Googlebot/2.1')->get('/igsn-search')->assertOk();

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(0);
});

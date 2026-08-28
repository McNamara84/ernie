<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\SuggestedOrcid;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(RefreshDatabase::class)->group('assessment', 'assistant', 'browser', 'resource-impact-filters');

beforeEach(function (): void {
    app(Vite::class)
        ->useHotFile(storage_path('framework/testing-vite.hot'))
        ->useBuildDirectory('build');

    Config::set('fuji.enabled', true);
    Config::set('fuji.base_url', 'https://fuji.test');
    Config::set('fuji.username', 'admin');
    Config::set('fuji.password', 'secret');
    Config::set('cache.default', 'array');
    Cache::flush();
    Http::fake([
        'https://fuji.test/fuji/api/v1/ui*' => Http::response('OK', 200),
    ]);
});

it('shows exact assessment matches and indirect assistance impacts for the same DOI and datacenter filter', function (): void {
    /** @var TestCase $this */
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $matchingDatacenter = Datacenter::factory()->create(['name' => 'Browser Filter Center']);
    $originDatacenter = Datacenter::factory()->create(['name' => 'Browser Origin Center']);
    $matchingResource = Resource::factory()->withDoi('10.5880/browser.filter.affected')->create([
        'datacenter_id' => $matchingDatacenter->id,
    ]);
    Title::factory()->for($matchingResource)->create(['value' => 'Matching browser assessment']);
    LandingPage::factory()->for($matchingResource)->withDoi((string) $matchingResource->doi)->published()->create();
    ResourceAssessment::query()->create([
        'resource_id' => $matchingResource->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 18.5,
        'assessed_identifier' => $matchingResource->doi,
        'assessed_at' => now(),
    ]);

    $unrelatedResource = Resource::factory()->withDoi('10.5880/browser.filter.unrelated')->create([
        'datacenter_id' => $originDatacenter->id,
    ]);
    Title::factory()->for($unrelatedResource)->create(['value' => 'Unrelated browser assessment']);
    LandingPage::factory()->for($unrelatedResource)->withDoi((string) $unrelatedResource->doi)->published()->create();
    ResourceAssessment::query()->create([
        'resource_id' => $unrelatedResource->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 7.5,
        'assessed_identifier' => $unrelatedResource->doi,
        'assessed_at' => now(),
    ]);

    $person = Person::factory()->create();
    ResourceCreator::create([
        'resource_id' => $unrelatedResource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);
    ResourceContributor::create([
        'resource_id' => $matchingResource->id,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'position' => 1,
    ]);
    SuggestedOrcid::create([
        'resource_id' => $unrelatedResource->id,
        'person_id' => $person->id,
        'suggested_orcid' => '0000-0001-5109-3700',
        'similarity_score' => 0.95,
        'candidate_first_name' => 'Browser',
        'candidate_last_name' => 'Candidate',
        'candidate_affiliations' => [],
        'source_context' => 'creator',
        'discovered_at' => now(),
    ]);

    $this->actingAs($admin);
    $query = '?doi='.rawurlencode('https://doi.org/10.5880/BROWSER.FILTER.AFFECTED')
        .'&datacenter_id='.$matchingDatacenter->id;

    visit('/assessment'.$query)
        ->assertNoSmoke()
        ->assertSee('DOI: 10.5880/browser.filter.affected')
        ->assertSee('Datacenter: Browser Filter Center')
        ->assertSee('Matching browser assessment')
        ->assertDontSee('Unrelated browser assessment');

    visit('/assistance'.$query)
        ->assertNoSmoke()
        ->assertSee('DOI: 10.5880/browser.filter.affected')
        ->assertSee('Datacenter: Browser Filter Center')
        ->assertSee('10.5880/browser.filter.unrelated')
        ->assertSee('Indirect match')
        ->assertSee('Affects 10.5880/browser.filter.affected');
});

<?php

declare(strict_types=1);

use App\Enums\ContributorCategory;
use App\Enums\EditorContext;
use App\Jobs\RefreshElmoCacheJob;
use App\Jobs\UpdatePidJob;
use App\Jobs\UpdateThesaurusJob;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;
use App\Models\Language;
use App\Models\PidSetting;
use App\Models\RelationType;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\ThesaurusSetting;
use App\Models\TitleType;
use App\Models\User;
use App\Services\EditorSettingsChangeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Helpers/ElmoCacheRefresh.php';

covers(EditorSettingsChangeService::class);

beforeEach(function (): void {
    configureElmoCacheRefreshTests();
    Http::preventStrayRequests();
    Bus::fake([RefreshElmoCacheJob::class]);
    $this->actingAs(User::factory()->admin()->create());
    $flags = ['is_active' => true, 'is_elmo_active' => true, 'is_elmo_msl_active' => true];
    ResourceType::factory()->create($flags);
    TitleType::factory()->create($flags);
    Right::factory()->create($flags);
    DateType::factory()->create($flags);
    Language::factory()->create(['active' => true, 'elmo_active' => true, 'elmo_msl_active' => true]);
    DescriptionType::create([...$flags, 'name' => 'Refresh Methods', 'slug' => 'RefreshMethods']);
    DescriptionType::updateOrCreate(['slug' => 'Abstract'], [...$flags, 'name' => 'Abstract']);
    foreach (ContributorCategory::cases() as $category) {
        ContributorType::create([...$flags, 'name' => 'Refresh '.$category->value, 'slug' => 'Refresh'.$category->value, 'category' => $category]);
    }
    RelationType::create([...$flags, 'name' => 'Refresh relation', 'slug' => 'RefreshRelation']);
    $identifier = IdentifierType::create([...$flags, 'name' => 'Refresh identifier', 'slug' => 'RefreshIdentifier']);
    IdentifierTypePattern::create(['identifier_type_id' => $identifier->id, 'type' => 'validation', 'pattern' => '^original$', 'is_active' => true, 'priority' => 10]);
    ThesaurusSetting::updateOrCreate(['type' => 'chronostratigraphy'], [...$flags, 'display_name' => 'Chronostratigraphy', 'version' => '2024-1']);
    PidSetting::updateOrCreate(['type' => 'pid4inst'], [...$flags, 'display_name' => 'PID4INST']);
    $props = $this->get('/settings')->assertOk()->viewData('page')['props'];
    $this->payload = array_intersect_key($props, array_flip([
        'resourceTypes', 'titleTypes', 'licenses', 'languages', 'dateTypes', 'descriptionTypes',
        'contributorPersonRoles', 'contributorInstitutionRoles', 'contributorBothRoles',
        'relationTypes', 'identifierTypes', 'thesauri', 'pidSettings',
    ]));
});

dataset('refresh settings groups', [
    'resource types' => ['resourceTypes'], 'title types' => ['titleTypes'], 'licenses' => ['licenses'],
    'languages' => ['languages'], 'date types' => ['dateTypes'], 'description types' => ['descriptionTypes'],
    'person roles' => ['contributorPersonRoles'], 'institution roles' => ['contributorInstitutionRoles'],
    'both roles' => ['contributorBothRoles'], 'relation types' => ['relationTypes'],
    'identifier types' => ['identifierTypes'], 'thesauri' => ['thesauri'], 'PID settings' => ['pidSettings'],
]);

it('refreshes only the variant whose activation changed', function (string $group, EditorContext $editor): void {
    $payload = $this->payload;
    $field = in_array($group, ['thesauri', 'pidSettings'], true)
        ? ($editor === EditorContext::ELMO ? 'isElmoActive' : 'isElmoMslActive')
        : ($editor === EditorContext::ELMO ? 'elmo_active' : 'elmo_msl_active');
    $index = $group === 'descriptionTypes'
        ? array_search('RefreshMethods', array_column($payload[$group], 'slug'), true)
        : 0;
    $payload[$group][$index][$field] = ! $payload[$group][$index][$field];

    $this->post('/settings', $payload)->assertSessionHasNoErrors()->assertRedirect();

    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 1);
    Bus::assertDispatched(RefreshElmoCacheJob::class, fn ($job): bool => $job->editor === $editor);
    Http::assertNothingSent();
})->with('refresh settings groups')->with([EditorContext::ELMO, EditorContext::ELMO_MSL]);

it('ignores ERNIE-only activation changes', function (string $group): void {
    $payload = $this->payload;
    $field = in_array($group, ['thesauri', 'pidSettings'], true) ? 'isActive' : 'active';
    $index = $group === 'descriptionTypes'
        ? array_search('RefreshMethods', array_column($payload[$group], 'slug'), true)
        : 0;
    $payload[$group][$index][$field] = ! $payload[$group][$index][$field];
    $this->post('/settings', $payload)->assertSessionHasNoErrors()->assertRedirect();
    Bus::assertNothingDispatched();
})->with('refresh settings groups');

it('saves MSL-only settings without dispatch while upstream MSL routes are not ready', function (): void {
    config(['elmo.targets.elmo-msl.ready' => false]);
    $payload = $this->payload;
    $payload['resourceTypes'][0]['elmo_msl_active'] = ! $payload['resourceTypes'][0]['elmo_msl_active'];
    $row = $payload['resourceTypes'][0];
    $this->post('/settings', $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(ResourceType::findOrFail($row['id'])->is_elmo_msl_active)->toBe($row['elmo_msl_active']);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

it('ignores unchanged values and reordered request rows', function (): void {
    $payload = $this->payload;
    foreach ($payload as &$rows) {
        $rows = array_reverse($rows);
    }
    unset($rows);
    $this->post('/settings', $payload)->assertSessionHasNoErrors()->assertRedirect();
    Bus::assertNothingDispatched();
});

it('refreshes both variants for shared visible names and slugs', function (string $group, string $field): void {
    $payload = $this->payload;
    $payload[$group][0][$field] .= ' revised';
    $this->post('/settings', $payload)->assertSessionHasNoErrors()->assertRedirect();
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 2);
})->with([['resourceTypes', 'name'], ['titleTypes', 'name'], ['titleTypes', 'slug']]);

it('refreshes shared changes only where the row is visible', function (bool $elmo, bool $msl, int $count): void {
    $id = $this->payload['resourceTypes'][0]['id'];
    DB::table('resource_types')->where('id', $id)->update(['is_elmo_active' => $elmo, 'is_elmo_msl_active' => $msl]);
    $payload = $this->payload;
    $payload['resourceTypes'][0]['elmo_active'] = $elmo;
    $payload['resourceTypes'][0]['elmo_msl_active'] = $msl;
    $payload['resourceTypes'][0]['name'] .= ' revised';
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, $count);
})->with([[true, false, 1], [false, true, 1], [false, false, 0]]);

it('compares license exclusion sets independently of order and timestamps', function (): void {
    $types = ResourceType::factory()->count(2)->create();
    $payload = $this->payload;
    $payload['licenses'][0]['elmo_excluded_resource_type_ids'] = $types->pluck('id')->all();
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 1);
    Bus::assertDispatched(RefreshElmoCacheJob::class, fn ($job): bool => $job->editor === EditorContext::ELMO);
    Bus::fake([RefreshElmoCacheJob::class]);
    $payload['licenses'][0]['elmo_excluded_resource_type_ids'] = array_reverse($types->pluck('id')->all());
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertNothingDispatched();
    $payload['licenses'][0]['elmo_excluded_resource_type_ids'] = [];
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 1);
});

it('ignores ERNIE exclusions and preserves omitted external fields', function (): void {
    $payload = $this->payload;
    foreach ($payload as $group => &$rows) {
        foreach ($rows as &$row) {
            unset($row['elmo_msl_active'], $row['isElmoMslActive']);
            if ($group === 'licenses') {
                unset($row['elmo_excluded_resource_type_ids'], $row['elmo_msl_excluded_resource_type_ids']);
                $row['excluded_resource_type_ids'] = [$payload['resourceTypes'][0]['id']];
            }
        }
        unset($row);
    }
    unset($rows);
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertNothingDispatched();
});

it('uses the actual Abstract values instead of a rejected deactivation', function (): void {
    $payload = $this->payload;
    $index = array_search('Abstract', array_column($payload['descriptionTypes'], 'slug'), true);
    foreach (['active', 'elmo_active', 'elmo_msl_active'] as $field) {
        $payload['descriptionTypes'][$index][$field] = false;
    }
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertNothingDispatched();
});

it('detects shared contributor category changes', function (): void {
    $payload = $this->payload;
    $payload['contributorPersonRoles'][0]['category'] = 'institution';
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 2);
});

it('detects active identifier pattern and priority changes', function (string $field, mixed $value): void {
    $payload = $this->payload;
    $index = array_search('RefreshIdentifier', array_column($payload['identifierTypes'], 'slug'), true);
    $payload['identifierTypes'][$index]['patterns'][0][$field] = $value;
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 2);
})->with([['pattern', '^revised$'], ['priority', 50], ['is_active', false]]);

it('ignores changes to inactive identifier patterns', function (): void {
    IdentifierTypePattern::query()->update(['is_active' => false]);
    $payload = $this->payload;
    $index = array_search('RefreshIdentifier', array_column($payload['identifierTypes'], 'slug'), true);
    $payload['identifierTypes'][$index]['patterns'][0]['is_active'] = false;
    $payload['identifierTypes'][$index]['patterns'][0]['pattern'] = '^revised$';
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertNothingDispatched();
});

it('does not refresh on invalid or unauthorized settings requests', function (bool $authorized): void {
    $payload = $this->payload;
    if ($authorized) {
        $payload['resourceTypes'][0]['elmo_active'] = 'invalid';
        $this->post('/settings', $payload)->assertSessionHasErrors();
    } else {
        $this->actingAs(User::factory()->create());
        $this->post('/settings', $payload)->assertForbidden();
    }
    Bus::assertNothingDispatched();
})->with([true, false]);

it('holds settings refreshes until the outer transaction commits or rolls back', function (bool $commit): void {
    $payload = $this->payload;
    $payload['resourceTypes'][0]['elmo_active'] = ! $payload['resourceTypes'][0]['elmo_active'];
    DB::beginTransaction();
    $this->post('/settings', $payload)->assertSessionHasNoErrors();
    Bus::assertNothingDispatched();
    if ($commit) {
        DB::commit();
        Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 1);
    } else {
        DB::rollBack();
        Bus::assertNothingDispatched();
    }
})->with([true, false]);

it('preserves successful settings when queue insertion fails', function (): void {
    $payload = $this->payload;
    $payload['resourceTypes'][0]['name'] = 'Saved despite queue failure';
    Bus::shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('queue failed'));
    $this->post('/settings', $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(ResourceType::findOrFail($payload['resourceTypes'][0]['id'])->name)->toBe('Saved despite queue failure');
});

it('does no snapshot work while the integration is disabled', function (): void {
    config(['elmo.enabled' => false]);
    expect(app(EditorSettingsChangeService::class)->snapshot())->toBe([]);
    $this->post('/settings', $this->payload)->assertSessionHasNoErrors();
    Bus::assertNothingDispatched();
});

it('refreshes after genuine version changes and retains files for identical versions', function (bool $changed): void {
    Storage::fake();
    $setting = ThesaurusSetting::updateOrCreate(['type' => 'analytical_methods'], [
        'display_name' => 'Analytical Methods', 'version' => '2024-1',
        'is_elmo_active' => true, 'is_elmo_msl_active' => true,
    ]);
    Storage::put($setting->getFilePath(), 'previous vocabulary');
    $this->patchJson('/thesauri/analytical_methods/version', ['version' => $changed ? '2024-2' : '2024-1'])->assertOk();
    if ($changed) {
        Storage::assertMissing($setting->getFilePath());
        Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 2);
    } else {
        Storage::assertExists($setting->getFilePath());
        Bus::assertNothingDispatched();
    }
})->with([true, false]);

it('refreshes only after successful data update commands', function (string $kind, int $exitCode): void {
    Artisan::shouldReceive('call')->once()->andReturn($exitCode);
    if ($exitCode !== 0) {
        Artisan::shouldReceive('output')->once()->andReturn('command failed');
    }
    $job = $kind === 'pid'
        ? new UpdatePidJob('pid4inst', (string) Str::uuid())
        : new UpdateThesaurusJob('chronostratigraphy', (string) Str::uuid());
    $job->handle();
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, $exitCode === 0 ? 2 : 0);
})->with([['pid', 0], ['pid', 1], ['thesaurus', 0], ['thesaurus', 1]]);

it('reads current activations after a data update has finished', function (): void {
    Artisan::shouldReceive('call')->once()->andReturnUsing(function (): int {
        PidSetting::where('type', 'pid4inst')->update(['is_elmo_active' => false, 'is_elmo_msl_active' => true]);

        return 0;
    });
    (new UpdatePidJob('pid4inst', (string) Str::uuid()))->handle();
    Bus::assertDispatchedTimes(RefreshElmoCacheJob::class, 1);
    Bus::assertDispatched(RefreshElmoCacheJob::class, fn ($job): bool => $job->editor === EditorContext::ELMO_MSL);
});

it('preserves completed data updates when refresh queue insertion fails', function (string $kind): void {
    Artisan::shouldReceive('call')->once()->andReturn(0);
    Bus::shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('queue failed'));
    $id = (string) Str::uuid();
    $job = $kind === 'pid' ? new UpdatePidJob('pid4inst', $id) : new UpdateThesaurusJob('chronostratigraphy', $id);
    $job->handle();
    expect(cache()->get($job::getCacheKey($id))['status'])->toBe('completed');
})->with(['pid', 'thesaurus']);

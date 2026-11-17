<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\Editor\EditorDataTransformer;
use App\Services\SizeFormat\ResourceFormatUsageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Cache::forget(ResourceFormatUsageService::CACHE_KEY);
    $this->usage = app(ResourceFormatUsageService::class);
    $this->datasetType = ResourceType::firstOrCreate(['slug' => 'dataset'], ['name' => 'Dataset']);
});

it('counts each registered format once per resource after normalizing aliases, parameters and case', function () {
    $first = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $second = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $second->formats()->create(['value' => 'text/csv']);
    foreach (['CSV', '.csv', 'TEXT/CSV; charset=UTF-8', 'text/csv', 'application/json', '', '   ', 'unknown legacy format'] as $value) {
        $first->formats()->create(['value' => $value]);
    }
    $second->formats()->create(['value' => 'JSON']);

    expect($this->usage->counts())->toHaveCount(2)->toMatchArray(['text/csv' => 2, 'application/json' => 2]);
});

it('uses the Resources population including untyped drafts and excludes IGSN physical objects', function () {
    $physical = ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'Physical Object']);
    $igsn = Resource::factory()->create(['resource_type_id' => $physical->id]);
    $draft = Resource::factory()->create(['resource_type_id' => null]);
    $dataset = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $igsn->formats()->create(['value' => 'application/pdf']);
    $draft->formats()->create(['value' => 'text/csv']);
    $dataset->formats()->create(['value' => 'application/json']);

    expect($this->usage->counts())->toHaveCount(2)->toMatchArray(['text/csv' => 1, 'application/json' => 1]);
});

it('ignores unregistered legacy formats without making them new dropdown options', function () {
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $resource->formats()->create(['value' => 'application/x-netcdf']);
    expect($this->usage->counts())->toBe([]);
    expect((array) app(EditorDataTransformer::class)->getCommonProps()['formatUsage'])->toBe([]);
});

it('reuses a bounded cache and refreshes popularity after one minute', function () {
    expect($this->usage->counts())->toBe([]);
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $resource->formats()->create(['value' => 'text/csv']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($this->usage->counts())->toBe([])->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    $this->travel(61)->seconds();
    expect($this->usage->counts())->toBe(['text/csv' => 1]);
});

it('supplies normalized popularity to new editors through common props', function () {
    $resource = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    $resource->formats()->create(['value' => 'CSV']);
    $this->actingAs(User::factory()->curator()->create())->get('/editor')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('editor')->where('formatUsage.text/csv', 1));
});

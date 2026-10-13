<?php

declare(strict_types=1);

use App\Http\Controllers\DateTypeController;
use App\Models\DateType;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);
covers(DateTypeController::class);

beforeEach(function () {
    Cache::flush();
    DateType::factory()->create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true, 'is_elmo_active' => true]);
    DateType::factory()->create(['name' => 'Collected', 'slug' => 'Collected', 'is_active' => true, 'is_elmo_active' => false]);
    DateType::factory()->create(['name' => 'Withdrawn', 'slug' => 'Withdrawn', 'is_active' => false, 'is_elmo_active' => true]);
});

describe('index', function () {
    it('returns all date types', function () {
        $response = $this->getJson('/api/v1/date-types');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    it('returns date types ordered by name', function () {
        $response = $this->getJson('/api/v1/date-types');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->toBe(['Collected', 'Created', 'Withdrawn']);
    });

    it('returns id, name and slug fields', function () {
        $response = $this->getJson('/api/v1/date-types');

        $response->assertJsonStructure([
            ['id', 'name', 'slug'],
        ]);
    });
});

describe('elmo', function () {
    it('returns independently ELMO-active date types with valid API key', function () {
        $response = $this->getJson('/api/v1/date-types/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(2);

        expect(collect($response->json())->pluck('slug')->all())->toBe(['Created', 'Withdrawn']);
    });

    it('rejects requests without API key', function () {
        $response = $this->getJson('/api/v1/date-types/elmo');

        $response->assertUnauthorized();
    });
});

describe('ernie', function () {
    it('orders by date entry usage excluding physical objects and preserves the API shape', function () {
        $created = DateType::where('slug', 'Created')->firstOrFail();
        $collected = DateType::where('slug', 'Collected')->firstOrFail();
        $inactive = DateType::where('slug', 'Withdrawn')->firstOrFail();
        $unused = DateType::factory()->create(['name' => 'Available', 'slug' => 'Available']);
        $dataset = Resource::factory()->create(['doi' => null]);
        $physicalType = ResourceType::factory()->create(['name' => 'Renamed samples', 'slug' => 'physical-object']);
        $physicalObject = Resource::factory()->create(['resource_type_id' => $physicalType->id]);
        $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
        $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2021']);
        $dataset->dates()->create(['date_type_id' => $collected->id, 'date_value' => '2020']);
        foreach (range(1, 5) as $index) {
            $physicalObject->dates()->create(['date_type_id' => $collected->id, 'date_value' => (string) (2000 + $index)]);
            $dataset->dates()->create(['date_type_id' => $inactive->id, 'date_value' => (string) (2000 + $index)]);
        }

        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertExactJson([
            ['id' => $created->id, 'name' => 'Created', 'slug' => 'Created'],
            ['id' => $collected->id, 'name' => 'Collected', 'slug' => 'Collected'],
            ['id' => $unused->id, 'name' => 'Available', 'slug' => 'Available'],
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertJsonPath('0.id', $created->id);
        expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'count(*)')))->toHaveCount(0);
    });

    it('orders ties alphabetically and then by ID, with unused types last', function () {
        $created = DateType::where('slug', 'Created')->firstOrFail();
        $collected = DateType::where('slug', 'Collected')->firstOrFail();
        $duplicateName = DateType::factory()->create(['name' => 'Created', 'slug' => 'created-alias']);
        $available = DateType::factory()->create(['name' => 'Available', 'slug' => 'Available']);
        $other = DateType::factory()->create(['name' => 'Other', 'slug' => 'Other']);
        $dataset = Resource::factory()->create();
        foreach ([$created, $collected, $duplicateName] as $type) {
            $dataset->dates()->create(['date_type_id' => $type->id, 'date_value' => '2020']);
        }

        $response = $this->getJson('/api/v1/date-types/ernie')->assertOk();

        expect(array_column($response->json(), 'id'))->toBe([$collected->id, $created->id, $duplicateName->id, $available->id, $other->id]);
    });

    it('falls back to alphabetical order when no eligible date entries exist', function (bool $physicalDates) {
        if ($physicalDates) {
            $physicalType = ResourceType::factory()->create(['name' => 'PhysicalObject', 'slug' => 'physical-object']);
            Resource::factory()->create(['resource_type_id' => $physicalType->id])->dates()->create([
                'date_type_id' => DateType::where('slug', 'Created')->firstOrFail()->id,
                'date_value' => '2020',
            ]);
        }

        $response = $this->getJson('/api/v1/date-types/ernie')->assertOk();

        expect(array_column($response->json(), 'name'))->toBe(['Collected', 'Created']);
    })->with([false, true]);

    it('immediately applies activation and name changes without rebuilding usage counts', function () {
        $created = DateType::where('slug', 'Created')->firstOrFail();
        $collected = DateType::where('slug', 'Collected')->firstOrFail();
        $withdrawn = DateType::where('slug', 'Withdrawn')->firstOrFail();
        $dataset = Resource::factory()->create();
        $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
        $dataset->dates()->create(['date_type_id' => $withdrawn->id, 'date_value' => '2021']);
        $dataset->dates()->create(['date_type_id' => $withdrawn->id, 'date_value' => '2022']);
        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertJsonPath('0.id', $created->id);

        $created->update(['is_active' => false]);
        $withdrawn->update(['is_active' => true, 'name' => 'Renamed withdrawn']);
        $newType = DateType::factory()->create(['name' => 'Available', 'slug' => 'Available']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertExactJson([
            ['id' => $withdrawn->id, 'name' => 'Renamed withdrawn', 'slug' => 'Withdrawn'],
            ['id' => $newType->id, 'name' => 'Available', 'slug' => 'Available'],
            ['id' => $collected->id, 'name' => 'Collected', 'slug' => 'Collected'],
        ]);
        expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'count(*)')))->toHaveCount(0);

        $collected->update(['name' => 'A collected date']);
        $response = $this->getJson('/api/v1/date-types/ernie')->assertOk();
        expect(array_column($response->json(), 'id'))->toBe([$withdrawn->id, $collected->id, $newType->id]);
    });

    it('refreshes the API order after seven days while retaining it before expiry', function () {
        $this->freezeTime();
        $created = DateType::where('slug', 'Created')->firstOrFail();
        $collected = DateType::where('slug', 'Collected')->firstOrFail();
        $dataset = Resource::factory()->create();
        $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertJsonPath('0.id', $created->id);

        $dataset->dates()->create(['date_type_id' => $collected->id, 'date_value' => '2021']);
        $dataset->dates()->create(['date_type_id' => $collected->id, 'date_value' => '2022']);
        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertJsonPath('0.id', $created->id);

        $this->travel(7)->days();
        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertJsonPath('0.id', $collected->id);
    });

    it('returns an empty JSON list when all types are disabled with a warm usage cache', function () {
        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertJsonCount(2);
        DateType::query()->update(['is_active' => false]);

        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertExactJson([]);
    });

    it('keeps the other date type endpoints alphabetical regardless of usage', function () {
        $created = DateType::where('slug', 'Created')->firstOrFail();
        $collected = DateType::where('slug', 'Collected')->firstOrFail();
        $withdrawn = DateType::where('slug', 'Withdrawn')->firstOrFail();
        $collected->update(['is_elmo_active' => true]);
        DateType::query()->update(['is_elmo_msl_active' => true]);
        Resource::factory()->create()->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
        $this->getJson('/api/v1/date-types/ernie')->assertOk()->assertJsonPath('0.id', $created->id);

        foreach (['/api/v1/date-types', '/api/v1/date-types/elmo', '/api/v1/date-types/elmo-msl'] as $endpoint) {
            $response = $this->getJson($endpoint, ['X-API-Key' => config('services.ernie.api_key')])->assertOk();
            expect(array_column($response->json(), 'id'))->toBe([$collected->id, $created->id, $withdrawn->id]);
        }
    });

    it('returns only active date types', function () {
        $response = $this->getJson('/api/v1/date-types/ernie');

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('excludes inactive date types', function () {
        $response = $this->getJson('/api/v1/date-types/ernie');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->not->toContain('Withdrawn');
    });
});

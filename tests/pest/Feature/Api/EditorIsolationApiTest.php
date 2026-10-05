<?php

declare(strict_types=1);

use App\Enums\ContributorCategory;
use App\Enums\EditorContext;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\Language;
use App\Models\RelationType;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['services.ernie.api_key' => 'editor-test-key']);
});

dataset('editor catalogs', [
    'resource types' => [ResourceType::class, 'resource-types'],
    'title types' => [TitleType::class, 'title-types'],
    'date types' => [DateType::class, 'date-types'],
    'description types' => [DescriptionType::class, 'description-types'],
    'languages' => [Language::class, 'languages'],
    'licenses' => [Right::class, 'licenses'],
    'relation types' => [RelationType::class, 'relation-types'],
    'identifier types' => [IdentifierType::class, 'identifier-types'],
    'author roles' => [ContributorType::class, 'roles/authors'],
    'person roles' => [ContributorType::class, 'roles/contributor-persons'],
    'institution roles' => [ContributorType::class, 'roles/contributor-institutions'],
]);

it('filters every combination of editor flags independently', function (string $model, string $path): void {
    $model::query()->delete();
    $records = [];
    for ($flags = 0; $flags < 8; $flags++) {
        $attributes = ['name' => 'Option '.$flags];
        if ($model === Language::class) {
            $attributes['code'] = 'x'.$flags;
        } elseif ($model === Right::class) {
            $attributes['identifier'] = 'TEST-'.$flags;
        } else {
            $attributes['slug'] = 'option-'.$flags;
        }
        if ($model === ContributorType::class) {
            $attributes['category'] = ContributorCategory::BOTH;
        }
        foreach (EditorContext::cases() as $index => $editor) {
            $attributes[$editor->activationColumn($model === Language::class)] = (bool) ($flags & (1 << $index));
        }
        $records[$flags] = $model::create($attributes)->id;
    }

    foreach (EditorContext::cases() as $index => $editor) {
        $response = $this->getJson('/api/v1/'.$path.'/'.$editor->value, ['X-API-Key' => 'editor-test-key'])->assertOk();
        $expected = array_values(array_filter($records, fn (int $id, int $flags): bool => (bool) ($flags & (1 << $index)), ARRAY_FILTER_USE_BOTH));
        expect(array_column($response->json(), 'id'))->toBe($expected);
        foreach ($response->json() as $row) {
            expect($row)->not->toHaveKeys(['is_active', 'is_elmo_active', 'is_elmo_msl_active', 'elmo_msl_active']);
        }
    }
})->with('editor catalogs');

it('keeps role categories and active identifier patterns in MSL responses', function (): void {
    ContributorType::query()->delete();
    $person = ContributorType::create(['name' => 'Person', 'slug' => 'person', 'category' => ContributorCategory::PERSON, 'is_active' => false, 'is_elmo_msl_active' => true]);
    $institution = ContributorType::create(['name' => 'Institution', 'slug' => 'institution', 'category' => ContributorCategory::INSTITUTION, 'is_elmo_msl_active' => true]);
    $this->getJson('/api/v1/roles/contributor-persons/elmo-msl', ['X-API-Key' => 'editor-test-key'])->assertJsonCount(1)->assertJsonPath('0.id', $person->id);
    $this->getJson('/api/v1/roles/contributor-institutions/elmo-msl', ['X-API-Key' => 'editor-test-key'])->assertJsonCount(1)->assertJsonPath('0.id', $institution->id);

    IdentifierType::query()->delete();
    $type = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => false, 'is_elmo_msl_active' => true]);
    $type->patterns()->createMany([
        ['type' => 'validation', 'pattern' => 'high', 'priority' => 90, 'is_active' => true],
        ['type' => 'validation', 'pattern' => 'low', 'priority' => 10, 'is_active' => true],
        ['type' => 'validation', 'pattern' => 'disabled', 'priority' => 100, 'is_active' => false],
    ]);
    $this->getJson('/api/v1/identifier-types/elmo-msl', ['X-API-Key' => 'editor-test-key'])->assertOk()
        ->assertJsonPath('0.patterns.validation.0.pattern', 'high')
        ->assertJsonPath('0.patterns.validation.1.pattern', 'low')
        ->assertJsonCount(2, '0.patterns.validation');
});

it('authenticates all 27 MSL routes and documents each registered route', function (): void {
    $spec = $this->getJson('/api/v1/doc')->assertOk()->assertJsonPath('openapi', '3.2.1')->assertJsonPath('info.version', '1.1.0')->json();
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_contains($route->uri(), 'elmo-msl'));
    expect($routes)->toHaveCount(27);
    foreach ($routes as $route) {
        $path = '/'.$route->uri();
        expect($spec['paths'])->toHaveKey($path);
        expect($spec['paths'][$path]['get']['security'])->toBe([['ElmoApiKey' => []], ['ErnieBearerKey' => []]]);
        $url = str_replace('{resourceTypeSlug}', 'dataset', $path);
        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['X-API-Key' => 'wrong'])->assertUnauthorized();
        $this->getJson($url.'?api_key=editor-test-key')->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer editor-test-key'])->assertStatus(
            $this->getJson($url, ['X-API-Key' => 'editor-test-key'])->status(),
        );
    }
    config(['services.ernie.api_key' => '']);
    $this->getJson('/api/v1/resource-types/elmo-msl', ['X-API-Key' => 'editor-test-key'])->assertUnauthorized();
});

it('isolates license exclusion writes and filtered responses for all three editors', function (): void {
    Right::query()->delete();
    $type = ResourceType::factory()->create(['slug' => 'editor-test-type']);
    $license = Right::factory()->create(['identifier' => 'TEST-LICENSE']);
    foreach (EditorContext::cases() as $editor) {
        $license->excludedResourceTypes($editor)->sync([$type->id]);
    }
    $license->excludedResourceTypes(EditorContext::ELMO_MSL)->sync([]);
    foreach (EditorContext::cases() as $editor) {
        $this->getJson('/api/v1/licenses/'.$editor->value.'/editor-test-type', ['X-API-Key' => 'editor-test-key'])
            ->assertOk()->assertJsonCount($editor === EditorContext::ELMO_MSL ? 1 : 0);
        $this->getJson('/api/v1/licenses/'.$editor->value.'/unknown-slug', ['X-API-Key' => 'editor-test-key'])->assertNotFound();
    }
    expect($license->excludedResourceTypes(EditorContext::ERNIE)->count())->toBe(1)
        ->and($license->excludedResourceTypes(EditorContext::ELMO)->count())->toBe(1)
        ->and($type->excludedFromRights(EditorContext::ELMO_MSL)->count())->toBe(0);
});

it('filters ERNIE license choices by resource type ID while retaining the unfiltered catalog', function (): void {
    Right::query()->delete();
    $type = ResourceType::factory()->create();
    $excluded = Right::factory()->create(['usage_count' => 20]);
    $allowed = Right::factory()->create(['usage_count' => 10]);
    $excluded->excludedResourceTypes(EditorContext::ERNIE)->attach($type->id);
    $allowed->excludedResourceTypes(EditorContext::ELMO)->attach($type->id);
    $this->getJson('/api/v1/licenses/ernie')->assertOk()->assertJsonCount(2)->assertJsonPath('0.id', $excluded->id);
    $this->getJson('/api/v1/licenses/ernie?resource_type_id='.$type->id)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $allowed->id);
    $this->getJson('/api/v1/licenses/ernie?resource_type_id=invalid')->assertUnprocessable()->assertJsonValidationErrors('resource_type_id');
    $this->getJson('/api/v1/licenses/ernie?resource_type_id=999999')->assertUnprocessable();
});

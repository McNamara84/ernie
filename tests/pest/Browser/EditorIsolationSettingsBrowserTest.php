<?php

declare(strict_types=1);

use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Vite;

uses()->group('browser');

it('saves and reloads an independent MSL resource type selection in the real settings form', function (): void {
    app(Vite::class)->useHotFile(storage_path('framework/testing-vite.hot'))->useBuildDirectory('build');
    $this->actingAs(User::factory()->admin()->create());
    TitleType::factory()->create();
    DateType::factory()->create();
    Language::factory()->create();
    Right::factory()->create();
    DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract']);
    $type = ResourceType::factory()->create(['name' => 'Browser resource type', 'is_active' => true, 'is_elmo_active' => false, 'is_elmo_msl_active' => false]);
    $page = visit('/settings')->assertNoSmoke()->click('Resource Types');
    $page->click('#elmo-msl-active-'.$type->id)
        ->assertChecked('#elmo-msl-active-'.$type->id)
        ->assertChecked('#active-'.$type->id)
        ->assertNotChecked('#elmo-active-'.$type->id)
        ->click('Save changes')->assertSee('Changes saved');
    expect($type->fresh()->is_active)->toBeTrue()
        ->and($type->fresh()->is_elmo_active)->toBeFalse()
        ->and($type->fresh()->is_elmo_msl_active)->toBeTrue();
    $headers = ['X-API-Key' => config('services.ernie.api_key')];
    $this->getJson('/api/v1/resource-types/ernie')->assertOk()->assertJsonFragment(['id' => $type->id]);
    $this->getJson('/api/v1/resource-types/elmo', $headers)->assertOk()->assertJsonMissing(['id' => $type->id]);
    $this->getJson('/api/v1/resource-types/elmo-msl', $headers)->assertOk()->assertJsonFragment(['id' => $type->id]);
    visit('/settings')->click('Resource Types')->assertChecked('#elmo-msl-active-'.$type->id)
        ->assertChecked('#active-'.$type->id)->assertNotChecked('#elmo-active-'.$type->id);
});

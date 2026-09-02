<?php

declare(strict_types=1);

use App\Models\DateType;
use App\Models\LandingPageTemplate;
use App\Models\RelationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('landing-page-templates');

it('provides the two type exclusion pivot tables', function (): void {
    expect(Schema::hasColumns('landing_page_template_date_type_exclusions', [
        'landing_page_template_id',
        'date_type_id',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('landing_page_template_relation_type_exclusions', [
            'landing_page_template_id',
            'relation_type_id',
        ]))->toBeTrue();
});

it('resolves excluded date and relation type ids and slugs', function (): void {
    $template = LandingPageTemplate::factory()->create();
    $dateTypes = [
        DateType::factory()->create(['name' => 'Zulu Date', 'slug' => 'ZuluDate']),
        DateType::factory()->create(['name' => 'Alpha Date', 'slug' => 'AlphaDate']),
    ];
    $relationTypes = [
        RelationType::query()->create(['name' => 'Zulu Relation', 'slug' => 'ZuluRelation', 'is_active' => true]),
        RelationType::query()->create(['name' => 'Alpha Relation', 'slug' => 'AlphaRelation', 'is_active' => false]),
    ];

    $template->excludedDateTypes()->attach([$dateTypes[0]->id, $dateTypes[1]->id]);
    $template->excludedRelationTypes()->attach([$relationTypes[0]->id, $relationTypes[1]->id]);

    expect($template->excludedDateTypes()->pluck('date_types.id')->sort()->values()->all())
        ->toBe([$dateTypes[0]->id, $dateTypes[1]->id])
        ->and($template->excludedRelationTypes()->pluck('relation_types.id')->sort()->values()->all())
        ->toBe([$relationTypes[0]->id, $relationTypes[1]->id])
        ->and($template->excludedDateTypeSlugs())
        ->toBe(['AlphaDate', 'ZuluDate'])
        ->and($template->excludedRelationTypeSlugs())
        ->toBe(['AlphaRelation', 'ZuluRelation']);
});

it('cascades exclusions when templates or vocabulary entries are deleted', function (): void {
    $template = LandingPageTemplate::factory()->create();
    $dateType = DateType::factory()->create(['name' => 'Cascade Date', 'slug' => 'CascadeDate']);
    $relationType = RelationType::query()->create([
        'name' => 'Cascade Relation',
        'slug' => 'CascadeRelation',
        'is_active' => true,
    ]);
    $template->excludedDateTypes()->attach($dateType->id);
    $template->excludedRelationTypes()->attach($relationType->id);

    $dateType->delete();
    $relationType->delete();

    expect(DB::table('landing_page_template_date_type_exclusions')->count())->toBe(0)
        ->and(DB::table('landing_page_template_relation_type_exclusions')->count())->toBe(0);

    $replacementDate = DateType::factory()->create(['name' => 'Replacement Date', 'slug' => 'ReplacementDate']);
    $replacementRelation = RelationType::query()->create([
        'name' => 'Replacement Relation',
        'slug' => 'ReplacementRelation',
        'is_active' => true,
    ]);
    $template->excludedDateTypes()->attach($replacementDate->id);
    $template->excludedRelationTypes()->attach($replacementRelation->id);
    $template->delete();

    expect(DB::table('landing_page_template_date_type_exclusions')->count())->toBe(0)
        ->and(DB::table('landing_page_template_relation_type_exclusions')->count())->toBe(0);
});

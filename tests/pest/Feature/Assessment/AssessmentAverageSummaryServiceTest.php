<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceType;
use App\Services\Assessment\AssessmentAverageSummaryService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('calculates separate averages for resources and igsns', function (): void {
    $physicalObjectType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => 'physical-object',
    ]);

    $datasetA = Resource::factory()->create();
    $datasetB = Resource::factory()->create();
    $igsnA = Resource::factory()->create([
        'resource_type_id' => $physicalObjectType->id,
    ]);
    $igsnB = Resource::factory()->create([
        'resource_type_id' => $physicalObjectType->id,
    ]);

    ResourceAssessment::query()->create([
        'resource_id' => $datasetA->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 7.2,
        'assessed_at' => now(),
    ]);
    ResourceAssessment::query()->create([
        'resource_id' => $datasetB->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 6.6,
        'assessed_at' => now(),
    ]);
    ResourceAssessment::query()->create([
        'resource_id' => $igsnA->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 2.8,
        'assessed_at' => now(),
    ]);
    ResourceAssessment::query()->create([
        'resource_id' => $igsnB->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 3.6,
        'assessed_at' => now(),
    ]);

    $summary = app(AssessmentAverageSummaryService::class)->getSidebarSummary($physicalObjectType->id);

    expect($summary)->toBe([
        'resources' => 6.9,
        'igsns' => 3.2,
        'formatted' => '6.9 / 3.2',
    ]);
});

it('ignores failed and unscored assessments when building averages', function (): void {
    $physicalObjectType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => 'physical-object',
    ]);

    $dataset = Resource::factory()->create();
    $igsn = Resource::factory()->create([
        'resource_type_id' => $physicalObjectType->id,
    ]);

    ResourceAssessment::query()->create([
        'resource_id' => $dataset->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 5.55,
        'assessed_at' => now(),
    ]);
    ResourceAssessment::query()->create([
        'resource_id' => $igsn->id,
        'status' => ResourceAssessment::STATUS_FAILED,
        'total_score' => 1.00,
        'assessed_at' => now(),
    ]);
    ResourceAssessment::query()->updateOrCreate(
        ['resource_id' => $igsn->id],
        [
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => null,
            'assessed_at' => now(),
        ],
    );

    $summary = app(AssessmentAverageSummaryService::class)->getSidebarSummary($physicalObjectType->id);

    expect($summary)->toBe([
        'resources' => 5.6,
        'igsns' => null,
        'formatted' => '5.6 / -',
    ]);
});

it('returns no formatted summary when no completed scored assessments exist', function (): void {
    $physicalObjectType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => 'physical-object',
    ]);

    Resource::factory()->create();
    Resource::factory()->create([
        'resource_type_id' => $physicalObjectType->id,
    ]);

    $summary = app(AssessmentAverageSummaryService::class)->getSidebarSummary($physicalObjectType->id);

    expect($summary)->toBe([
        'resources' => null,
        'igsns' => null,
        'formatted' => null,
    ]);
});

it('invalidates the cached summary when an assessment changes', function (): void {
    $physicalObjectType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => 'physical-object',
    ]);

    $dataset = Resource::factory()->create();

    $assessment = ResourceAssessment::query()->create([
        'resource_id' => $dataset->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 6.0,
        'assessed_at' => now(),
    ]);

    $service = app(AssessmentAverageSummaryService::class);

    expect($service->getSidebarSummary($physicalObjectType->id)['formatted'])->toBe('6.0 / -');

    $assessment->update([
        'total_score' => 8.0,
    ]);

    expect($service->getSidebarSummary($physicalObjectType->id))->toBe([
        'resources' => 8.0,
        'igsns' => null,
        'formatted' => '8.0 / -',
    ]);
});
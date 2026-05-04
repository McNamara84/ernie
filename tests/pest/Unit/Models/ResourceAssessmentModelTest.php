<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceAssessment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

covers(Resource::class, ResourceAssessment::class);

describe('Resource assessment relations', function (): void {
    it('exposes the has-one relation from resources to resource assessments', function (): void {
        $resource = new Resource;

        expect($resource->resourceAssessment())
            ->toBeInstanceOf(HasOne::class);
    });

    it('exposes fillable, casts, statuses, and the owning resource relation', function (): void {
        $assessment = new ResourceAssessment;

        expect(ResourceAssessment::STATUSES)->toBe([
            ResourceAssessment::STATUS_COMPLETED,
            ResourceAssessment::STATUS_FAILED,
            ResourceAssessment::STATUS_SKIPPED,
        ]);

        expect($assessment->getFillable())->toContain(
            'resource_id',
            'status',
            'total_score',
            'assessed_identifier',
            'error_message',
            'payload',
            'assessed_at',
        )->and($assessment->getCasts())->toMatchArray([
            'total_score' => 'decimal:2',
            'payload' => 'array',
            'assessed_at' => 'datetime',
        ])->and($assessment->resource())
            ->toBeInstanceOf(BelongsTo::class);
    });
});
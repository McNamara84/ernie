<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\Resource;

covers(IgsnMetadata::class);

describe('fillable', function () {
    it('has correct fillable fields', function () {
        $model = new IgsnMetadata;

        expect($model->getFillable())->toContain(
            'resource_id',
            'parent_resource_id',
            'sample_type',
            'material',
            'is_private',
            'depth_min',
            'depth_max',
            'upload_status',
            'upload_error_message',
            'csv_filename',
            'csv_row_number',
        );
    });
});

describe('table', function () {
    it('uses igsn_metadata table', function () {
        expect((new IgsnMetadata)->getTable())->toBe('igsn_metadata');
    });
});

describe('casts', function () {
    it('casts is_private to boolean', function () {
        $model = new IgsnMetadata(['is_private' => 1]);

        expect($model->is_private)->toBeBool();
    });

    it('casts description_json to array', function () {
        $model = new IgsnMetadata(['description_json' => '{"key": "value"}']);

        expect($model->description_json)->toBeArray();
    });

    it('casts csv_row_number to integer', function () {
        $model = new IgsnMetadata(['csv_row_number' => '5']);

        expect($model->csv_row_number)->toBeInt();
    });
});

describe('relationships', function () {
    it('defines resource relationship', function () {
        $model = new IgsnMetadata;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('defines parentResource relationship', function () {
        $model = new IgsnMetadata;

        expect($model->parentResource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('defines children relationship', function () {
        $model = new IgsnMetadata;

        expect($model->children())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
    });
});

describe('status constants', function () {
    it('defines all expected status constants', function () {
        expect(IgsnMetadata::STATUS_PENDING)->toBe('pending')
            ->and(IgsnMetadata::STATUS_UPLOADED)->toBe('uploaded')
            ->and(IgsnMetadata::STATUS_VALIDATING)->toBe('validating')
            ->and(IgsnMetadata::STATUS_VALIDATED)->toBe('validated')
            ->and(IgsnMetadata::STATUS_REGISTERING)->toBe('registering')
            ->and(IgsnMetadata::STATUS_REGISTERED)->toBe('registered')
            ->and(IgsnMetadata::STATUS_ERROR)->toBe('error');
    });
});

describe('getValidStatuses', function () {
    it('returns all 7 valid statuses', function () {
        $statuses = IgsnMetadata::getValidStatuses();

        expect($statuses)->toHaveCount(7)
            ->and($statuses)->toContain('pending', 'uploaded', 'validating', 'validated', 'registering', 'registered', 'error');
    });
});

describe('hasParent', function () {
    it('returns true when parent_resource_id is set', function () {
        $model = new IgsnMetadata(['parent_resource_id' => 42]);

        expect($model->hasParent())->toBeTrue();
    });

    it('returns false when parent_resource_id is null', function () {
        $model = new IgsnMetadata(['parent_resource_id' => null]);

        expect($model->hasParent())->toBeFalse();
    });
});

describe('isRoot', function () {
    it('returns true when parent_resource_id is null', function () {
        $model = new IgsnMetadata(['parent_resource_id' => null]);

        expect($model->isRoot())->toBeTrue();
    });

    it('returns false when parent_resource_id is set', function () {
        $model = new IgsnMetadata(['parent_resource_id' => 42]);

        expect($model->isRoot())->toBeFalse();
    });
});

describe('hasError', function () {
    it('returns true when upload_status is error', function () {
        $model = new IgsnMetadata(['upload_status' => 'error']);

        expect($model->hasError())->toBeTrue();
    });

    it('returns false for other statuses', function () {
        $model = new IgsnMetadata(['upload_status' => 'pending']);

        expect($model->hasError())->toBeFalse();
    });
});

describe('isRegistered', function () {
    it('returns true when upload_status is registered', function () {
        $model = new IgsnMetadata(['upload_status' => 'registered']);

        expect($model->isRegistered())->toBeTrue();
    });

    it('returns false for other statuses', function () {
        $model = new IgsnMetadata(['upload_status' => 'validated']);

        expect($model->isRegistered())->toBeFalse();
    });
});

describe('markAsError', function () {
    it('updates status to error with message', function () {
        $resource = Resource::factory()->create();
        $metadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'pending',
        ]);

        $metadata->markAsError('Something went wrong');
        $metadata->refresh();

        expect($metadata->upload_status)->toBe('error')
            ->and($metadata->upload_error_message)->toBe('Something went wrong');
    });
});

describe('updateStatus', function () {
    it('updates to valid status and clears error message', function () {
        $resource = Resource::factory()->create();
        $metadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => 'error',
            'upload_error_message' => 'Previous error',
        ]);

        $metadata->updateStatus('validated');
        $metadata->refresh();

        expect($metadata->upload_status)->toBe('validated')
            ->and($metadata->upload_error_message)->toBeNull();
    });

    it('throws exception for invalid status', function () {
        $model = new IgsnMetadata;

        expect(fn () => $model->updateStatus('nonexistent'))
            ->toThrow(\InvalidArgumentException::class);
    });
});

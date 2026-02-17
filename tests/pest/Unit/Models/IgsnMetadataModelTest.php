<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $physObj = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
    );

    $this->resource = Resource::factory()->create(['resource_type_id' => $physObj->id]);
    $this->metadata = IgsnMetadata::create([
        'resource_id' => $this->resource->id,
        'sample_type' => 'rock core',
        'material' => 'granite',
        'upload_status' => IgsnMetadata::STATUS_PENDING,
    ]);
});

describe('status constants', function () {
    test('has all 7 valid statuses', function () {
        $statuses = IgsnMetadata::getValidStatuses();

        expect($statuses)->toHaveCount(7)
            ->and($statuses)->toContain('pending', 'uploaded', 'validating', 'validated', 'registering', 'registered', 'error');
    });
});

describe('updateStatus', function () {
    test('updates status to valid value', function () {
        $this->metadata->updateStatus(IgsnMetadata::STATUS_UPLOADED);

        expect($this->metadata->fresh()->upload_status)->toBe('uploaded');
    });

    test('clears error message on status update', function () {
        $this->metadata->markAsError('Something went wrong');
        $this->metadata->updateStatus(IgsnMetadata::STATUS_PENDING);

        $fresh = $this->metadata->fresh();
        expect($fresh->upload_error_message)->toBeNull();
    });

    test('throws InvalidArgumentException for invalid status', function () {
        $this->metadata->updateStatus('nonexistent-status');
    })->throws(\InvalidArgumentException::class);
});

describe('markAsError', function () {
    test('sets error status and message', function () {
        $this->metadata->markAsError('Duplicate IGSN detected');

        $fresh = $this->metadata->fresh();
        expect($fresh->upload_status)->toBe('error')
            ->and($fresh->upload_error_message)->toBe('Duplicate IGSN detected');
    });
});

describe('status checks', function () {
    test('hasError returns true for error status', function () {
        $this->metadata->update(['upload_status' => IgsnMetadata::STATUS_ERROR]);

        expect($this->metadata->hasError())->toBeTrue();
    });

    test('hasError returns false for non-error status', function () {
        expect($this->metadata->hasError())->toBeFalse();
    });

    test('isRegistered returns true for registered status', function () {
        $this->metadata->update(['upload_status' => IgsnMetadata::STATUS_REGISTERED]);

        expect($this->metadata->isRegistered())->toBeTrue();
    });

    test('isRegistered returns false for pending status', function () {
        expect($this->metadata->isRegistered())->toBeFalse();
    });
});

describe('hierarchy', function () {
    test('hasParent returns false for root IGSN', function () {
        expect($this->metadata->hasParent())->toBeFalse();
    });

    test('isRoot returns true for IGSN without parent', function () {
        expect($this->metadata->isRoot())->toBeTrue();
    });

    test('hasParent returns true for child IGSN', function () {
        $childResource = Resource::factory()->create();
        $childMetadata = IgsnMetadata::create([
            'resource_id' => $childResource->id,
            'parent_resource_id' => $this->resource->id,
            'upload_status' => IgsnMetadata::STATUS_PENDING,
        ]);

        expect($childMetadata->hasParent())->toBeTrue()
            ->and($childMetadata->isRoot())->toBeFalse();
    });

    test('hasChildren returns true when children exist', function () {
        $childResource = Resource::factory()->create();
        IgsnMetadata::create([
            'resource_id' => $childResource->id,
            'parent_resource_id' => $this->resource->id,
            'upload_status' => IgsnMetadata::STATUS_PENDING,
        ]);

        expect($this->metadata->hasChildren())->toBeTrue();
    });

    test('hasChildren returns false when no children', function () {
        expect($this->metadata->hasChildren())->toBeFalse();
    });
});

describe('relationships', function () {
    test('belongs to resource', function () {
        expect($this->metadata->resource->id)->toBe($this->resource->id);
    });

    test('belongs to parent resource', function () {
        $childResource = Resource::factory()->create();
        $childMetadata = IgsnMetadata::create([
            'resource_id' => $childResource->id,
            'parent_resource_id' => $this->resource->id,
            'upload_status' => IgsnMetadata::STATUS_PENDING,
        ]);

        expect($childMetadata->parentResource->id)->toBe($this->resource->id);
    });
});

describe('attribute casting', function () {
    test('is_private is cast to boolean', function () {
        $this->metadata->update(['is_private' => 1]);

        expect($this->metadata->fresh()->is_private)->toBeBool()
            ->and($this->metadata->fresh()->is_private)->toBeTrue();
    });

    test('description_json is cast to array', function () {
        $this->metadata->update(['description_json' => ['key' => 'value']]);

        expect($this->metadata->fresh()->description_json)->toBeArray()
            ->and($this->metadata->fresh()->description_json['key'])->toBe('value');
    });
});

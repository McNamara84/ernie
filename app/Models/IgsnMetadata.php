<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IGSN Metadata Model
 *
 * Stores IGSN-specific metadata for physical samples that extends
 * the standard DataCite Resource schema. Has a 1:1 relationship with Resource.
 *
 * Supports hierarchical parent-child relationships:
 * - Borehole (parent)
 *   - Core Section (child of borehole)
 *     - Sample (child of core section)
 *
 * @property int $id
 * @property int $resource_id
 * @property int|null $parent_resource_id
 * @property string|null $sample_type
 * @property string|null $material
 * @property bool $is_private
 * @property string|null $size
 * @property string|null $size_unit
 * @property string|null $depth_min
 * @property string|null $depth_max
 * @property string|null $depth_scale
 * @property string|null $sample_purpose
 * @property string|null $collection_method
 * @property string|null $collection_method_description
 * @property string|null $collection_date_precision
 * @property string|null $cruise_field_program
 * @property string|null $platform_type
 * @property string|null $platform_name
 * @property string|null $platform_description
 * @property string|null $current_archive
 * @property string|null $current_archive_contact
 * @property string|null $sample_access
 * @property string|null $operator
 * @property string|null $coordinate_system
 * @property string|null $user_code
 * @property array<mixed>|null $description_json
 * @property string $upload_status
 * @property string|null $upload_error_message
 * @property string|null $csv_filename
 * @property int|null $csv_row_number
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read Resource|null $parentResource
 * @property-read Collection<int, IgsnMetadata> $children
 */
class IgsnMetadata extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'igsn_metadata';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'resource_id',
        'parent_resource_id',
        'sample_type',
        'material',
        'is_private',
        'size',
        'size_unit',
        'depth_min',
        'depth_max',
        'depth_scale',
        'sample_purpose',
        'collection_method',
        'collection_method_description',
        'collection_date_precision',
        'cruise_field_program',
        'platform_type',
        'platform_name',
        'platform_description',
        'current_archive',
        'current_archive_contact',
        'sample_access',
        'operator',
        'coordinate_system',
        'user_code',
        'description_json',
        'upload_status',
        'upload_error_message',
        'csv_filename',
        'csv_row_number',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_private' => 'boolean',
        'size' => 'decimal:4',
        'depth_min' => 'decimal:2',
        'depth_max' => 'decimal:2',
        'description_json' => 'array',
        'csv_row_number' => 'integer',
    ];

    /**
     * Valid upload status values.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_REGISTERING = 'registering';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_ERROR = 'error';

    /**
     * Get the resource that owns this metadata.
     *
     * @return BelongsTo<Resource, static>
     */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /**
     * Get the parent resource (for hierarchical IGSNs).
     *
     * @return BelongsTo<Resource, static>
     */
    public function parentResource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class, 'parent_resource_id');

        return $relation;
    }

    /**
     * Get the child IGSN metadata records.
     *
     * @return HasMany<IgsnMetadata, static>
     */
    public function children(): HasMany
    {
        /** @var HasMany<IgsnMetadata, static> $relation */
        $relation = $this->hasMany(IgsnMetadata::class, 'parent_resource_id', 'resource_id');

        return $relation;
    }

    /**
     * Check if this IGSN has a parent.
     */
    public function hasParent(): bool
    {
        return $this->parent_resource_id !== null;
    }

    /**
     * Check if this IGSN has children.
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Check if this IGSN is a root (no parent).
     */
    public function isRoot(): bool
    {
        return $this->parent_resource_id === null;
    }

    /**
     * Check if the upload has an error status.
     */
    public function hasError(): bool
    {
        return $this->upload_status === self::STATUS_ERROR;
    }

    /**
     * Check if the IGSN is registered.
     */
    public function isRegistered(): bool
    {
        return $this->upload_status === self::STATUS_REGISTERED;
    }

    /**
     * Mark the upload as having an error.
     */
    public function markAsError(string $message): void
    {
        $this->update([
            'upload_status' => self::STATUS_ERROR,
            'upload_error_message' => $message,
        ]);
    }

    /**
     * Get all valid status values.
     *
     * @return list<string>
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_UPLOADED,
            self::STATUS_VALIDATING,
            self::STATUS_VALIDATED,
            self::STATUS_REGISTERING,
            self::STATUS_REGISTERED,
            self::STATUS_ERROR,
        ];
    }

    /**
     * Update the upload status.
     *
     * @throws \InvalidArgumentException If the status is not a valid status constant
     */
    public function updateStatus(string $status): void
    {
        if (! in_array($status, self::getValidStatuses(), true)) {
            throw new \InvalidArgumentException(
                "Invalid status '{$status}'. Valid statuses are: " . implode(', ', self::getValidStatuses())
            );
        }

        $this->update([
            'upload_status' => $status,
            'upload_error_message' => null,
        ]);
    }
}

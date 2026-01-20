<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Rights Model (DataCite #16) - formerly License
 *
 * Stores SPDX license information for resource rights management.
 *
 * @property int $id
 * @property string $identifier
 * @property string $name
 * @property string|null $uri
 * @property string|null $scheme_uri
 * @property bool $is_active
 * @property bool $is_elmo_active
 * @property int $usage_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/rights/
 */
class Right extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'identifier',
        'name',
        'uri',
        'scheme_uri',
        'is_active',
        'is_elmo_active',
        'usage_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_elmo_active' => 'boolean',
        'usage_count' => 'integer',
    ];

    /**
     * @param  Builder<Right>  $query
     * @return Builder<Right>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Right>  $query
     * @return Builder<Right>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('is_elmo_active', true);
    }

    /**
     * @param  Builder<Right>  $query
     * @return Builder<Right>
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Order rights by usage count (descending) with alphabetical fallback.
     *
     * @param  Builder<Right>  $query
     * @return Builder<Right>
     */
    public function scopeOrderByUsageCount(Builder $query): Builder
    {
        return $query->orderBy('usage_count', 'desc')->orderBy('name');
    }

    /** @return BelongsToMany<Resource, static, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'> */
    public function resources(): BelongsToMany
    {
        /** @var BelongsToMany<Resource, static, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'> $relation */
        $relation = $this->belongsToMany(Resource::class, 'resource_rights')
            ->withTimestamps();

        return $relation;
    }

    /**
     * Resource types that are EXCLUDED from this license (blacklist approach).
     *
     * If a resource type is in this relationship, the license is NOT available for that type.
     *
     * @return BelongsToMany<ResourceType, static, Pivot, 'pivot'>
     */
    public function excludedResourceTypes(): BelongsToMany
    {
        /** @var BelongsToMany<ResourceType, static, Pivot, 'pivot'> $relation */
        $relation = $this->belongsToMany(
            ResourceType::class,
            'right_resource_type_exclusions',
            'right_id',
            'resource_type_id'
        )->withTimestamps();

        return $relation;
    }

    /**
     * Check if this license is available for a given resource type.
     */
    public function isAvailableForResourceType(int $resourceTypeId): bool
    {
        return ! $this->excludedResourceTypes()
            ->where('resource_types.id', $resourceTypeId)
            ->exists();
    }

    /**
     * Scope to filter licenses available for a specific resource type.
     *
     * Returns licenses that do NOT have the given resource type in their exclusion list.
     *
     * @param  Builder<Right>  $query
     * @return Builder<Right>
     */
    public function scopeAvailableForResourceType(Builder $query, int $resourceTypeId): Builder
    {
        return $query->whereDoesntHave('excludedResourceTypes', function (Builder $q) use ($resourceTypeId): void {
            $q->where('resource_types.id', $resourceTypeId);
        });
    }
}

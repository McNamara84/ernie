<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
     * @param Builder<Right> $query
     * @return Builder<Right>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<Right> $query
     * @return Builder<Right>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('is_elmo_active', true);
    }

    /**
     * @param Builder<Right> $query
     * @return Builder<Right>
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Order rights by usage count (descending) with alphabetical fallback.
     *
     * @param Builder<Right> $query
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
}

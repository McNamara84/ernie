<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contributor Type Lookup Model (DataCite #7)
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/contributor/
 */
class ContributorType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * @param Builder<ContributorType> $query
     * @return Builder<ContributorType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return HasMany<ResourceContributor, static> */
    public function contributors(): HasMany
    {
        /** @var HasMany<ResourceContributor, static> $relation */
        $relation = $this->hasMany(ResourceContributor::class);

        return $relation;
    }
}

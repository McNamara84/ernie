<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContributorCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contributor Type Lookup Model (DataCite #7)
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ContributorCategory $category
 * @property bool $is_active
 * @property bool $is_elmo_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/
 */
#[Fillable(['name', 'slug', 'category', 'is_active', 'is_elmo_active'])]
class ContributorType extends Model
{

    protected $casts = [
        'category' => ContributorCategory::class,
        'is_active' => 'boolean',
        'is_elmo_active' => 'boolean',
    ];

    /**
     * @param  Builder<ContributorType>  $query
     * @return Builder<ContributorType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ContributorType>  $query
     * @return Builder<ContributorType>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('is_elmo_active', true);
    }

    /**
     * Filter types applicable to persons (category = person or both).
     *
     * @param  Builder<ContributorType>  $query
     * @return Builder<ContributorType>
     */
    public function scopeForPersons(Builder $query): Builder
    {
        return $query->whereIn('category', [ContributorCategory::PERSON, ContributorCategory::BOTH]);
    }

    /**
     * Filter types applicable to institutions (category = institution or both).
     *
     * @param  Builder<ContributorType>  $query
     * @return Builder<ContributorType>
     */
    public function scopeForInstitutions(Builder $query): Builder
    {
        return $query->whereIn('category', [ContributorCategory::INSTITUTION, ContributorCategory::BOTH]);
    }

    /** @return HasMany<ResourceContributor, static> */
    public function contributors(): HasMany
    {
        /** @var HasMany<ResourceContributor, static> $relation */
        $relation = $this->hasMany(ResourceContributor::class);

        return $relation;
    }
}

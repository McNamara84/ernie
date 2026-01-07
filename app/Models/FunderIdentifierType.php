<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Funder Identifier Type Lookup Model (DataCite #19)
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/fundingreference/
 */
class FunderIdentifierType extends Model
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
     * @param  Builder<FunderIdentifierType>  $query
     * @return Builder<FunderIdentifierType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return HasMany<FundingReference, static> */
    public function fundingReferences(): HasMany
    {
        /** @var HasMany<FundingReference, static> $relation */
        $relation = $this->hasMany(FundingReference::class);

        return $relation;
    }
}

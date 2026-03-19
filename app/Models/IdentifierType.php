<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identifier Type Lookup Model (DataCite #12)
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property bool $is_elmo_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/
 */
#[Fillable(['name', 'slug', 'is_active', 'is_elmo_active'])]
class IdentifierType extends Model
{

    protected $casts = [
        'is_active' => 'boolean',
        'is_elmo_active' => 'boolean',
    ];

    /**
     * @param  Builder<IdentifierType>  $query
     * @return Builder<IdentifierType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<IdentifierType>  $query
     * @return Builder<IdentifierType>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('is_elmo_active', true);
    }

    /**
     * @param  Builder<IdentifierType>  $query
     * @return Builder<IdentifierType>
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /** @return HasMany<IdentifierTypePattern, static> */
    public function patterns(): HasMany
    {
        /** @var HasMany<IdentifierTypePattern, static> $relation */
        $relation = $this->hasMany(IdentifierTypePattern::class);

        return $relation;
    }

    /** @return HasMany<RelatedIdentifier, static> */
    public function relatedIdentifiers(): HasMany
    {
        /** @var HasMany<RelatedIdentifier, static> $relation */
        $relation = $this->hasMany(RelatedIdentifier::class);

        return $relation;
    }
}

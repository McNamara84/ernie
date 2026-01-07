<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relation Type Lookup Model (DataCite #12)
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/relatedidentifier/
 */
class RelationType extends Model
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
     * @param  Builder<RelationType>  $query
     * @return Builder<RelationType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return HasMany<RelatedIdentifier, static> */
    public function relatedIdentifiers(): HasMany
    {
        /** @var HasMany<RelatedIdentifier, static> $relation */
        $relation = $this->hasMany(RelatedIdentifier::class);

        return $relation;
    }
}

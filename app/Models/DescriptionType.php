<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Description Type Lookup Model (DataCite #17)
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/description/
 */
class DescriptionType extends Model
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
     * @param Builder<DescriptionType> $query
     * @return Builder<DescriptionType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return HasMany<Description, static> */
    public function descriptions(): HasMany
    {
        /** @var HasMany<Description, static> $relation */
        $relation = $this->hasMany(Description::class);

        return $relation;
    }
}

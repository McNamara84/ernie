<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ResourceType extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'is_elmo_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_elmo_active' => 'boolean',
    ];

    /**
     * @param  Builder<ResourceType>  $query
     * @return Builder<ResourceType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ResourceType>  $query
     * @return Builder<ResourceType>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('is_elmo_active', true);
    }

    /**
     * @param  Builder<ResourceType>  $query
     * @return Builder<ResourceType>
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Get the resources with this resource type.
     *
     * @return HasMany<\App\Models\Resource, static>
     */
    public function resources(): HasMany
    {
        /** @var HasMany<\App\Models\Resource, static> $relation */
        $relation = $this->hasMany(Resource::class);

        return $relation;
    }

    /**
     * Licenses (Rights) that EXCLUDE this resource type.
     *
     * If a license is in this relationship, that license is NOT available for this resource type.
     *
     * @return BelongsToMany<Right, static, Pivot, 'pivot'>
     */
    public function excludedFromRights(): BelongsToMany
    {
        /** @var BelongsToMany<Right, static, Pivot, 'pivot'> $relation */
        $relation = $this->belongsToMany(
            Right::class,
            'right_resource_type_exclusions',
            'resource_type_id',
            'right_id'
        )->withTimestamps();

        return $relation;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceType extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'active',
        'elmo_active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'elmo_active' => 'boolean',
    ];

    /**
     * @param Builder<ResourceType> $query
     * @return Builder<ResourceType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param Builder<ResourceType> $query
     * @return Builder<ResourceType>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('elmo_active', true);
    }

    /**
     * @param Builder<ResourceType> $query
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
}

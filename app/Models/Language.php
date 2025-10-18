<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'active',
        'elmo_active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'elmo_active' => 'boolean',
    ];

    /**
     * @param Builder<Language> $query
     * @return Builder<Language>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param Builder<Language> $query
     * @return Builder<Language>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('elmo_active', true);
    }

    /**
     * @param Builder<Language> $query
     * @return Builder<Language>
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Get the resources with this language.
     *
     * @return HasMany<Resource, static>
     */
    public function resources(): HasMany
    {
        /** @var HasMany<Resource, static> $relation */
        $relation = $this->hasMany(Resource::class);

        return $relation;
    }
}

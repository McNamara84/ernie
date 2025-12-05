<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DateType extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'is_elmo_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_elmo_active' => 'boolean',
    ];

    /**
     * Scope to filter only active date types (for ERNIE).
     *
     * @param  Builder<DateType>  $query
     * @return Builder<DateType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter only ELMO-active date types.
     *
     * @param  Builder<DateType>  $query
     * @return Builder<DateType>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('is_elmo_active', true);
    }

    /**
     * Scope to order by name alphabetically.
     *
     * @param  Builder<DateType>  $query
     * @return Builder<DateType>
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Get the resource dates with this date type.
     *
     * @return HasMany<\App\Models\ResourceDate, static>
     */
    public function dates(): HasMany
    {
        /** @var HasMany<\App\Models\ResourceDate, static> $relation */
        $relation = $this->hasMany(ResourceDate::class);

        return $relation;
    }
}

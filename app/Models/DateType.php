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
        'description',
        'active',
        'elmo_active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'elmo_active' => 'boolean',
    ];

    /**
     * Scope to filter only active date types (for ERNIE).
     *
     * @param  Builder<DateType>  $query
     * @return Builder<DateType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Scope to filter only ELMO-active date types.
     *
     * @param  Builder<DateType>  $query
     * @return Builder<DateType>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('elmo_active', true);
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

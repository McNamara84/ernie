<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class License extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'identifier',
        'name',
        'spdx_id',
        'reference',
        'details_url',
        'is_deprecated_license_id',
        'is_osi_approved',
        'is_fsf_libre',
        'active',
        'elmo_active',
    ];

    protected $casts = [
        'is_deprecated_license_id' => 'boolean',
        'is_osi_approved' => 'boolean',
        'is_fsf_libre' => 'boolean',
        'active' => 'boolean',
        'elmo_active' => 'boolean',
        'usage_count' => 'integer',
    ];

    /**
     * @param  Builder<License>  $query
     * @return Builder<License>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<License>  $query
     * @return Builder<License>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('elmo_active', true);
    }

    /**
     * @param  Builder<License>  $query
     * @return Builder<License>
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Order licenses by usage count (descending) with alphabetical fallback.
     *
     * @param  Builder<License>  $query
     * @return Builder<License>
     */
    public function scopeOrderByUsageCount(Builder $query): Builder
    {
        return $query->orderBy('usage_count', 'desc')->orderBy('name');
    }
}

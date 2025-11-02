<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitleType extends Model
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
     * @param  Builder<TitleType>  $query
     * @return Builder<TitleType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<TitleType>  $query
     * @return Builder<TitleType>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('elmo_active', true);
    }

    /**
     * @param  Builder<TitleType>  $query
     * @return Builder<TitleType>
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }
}

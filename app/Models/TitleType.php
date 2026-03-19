<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'is_active', 'is_elmo_active'])]
class TitleType extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'is_elmo_active' => 'boolean',
    ];

    /**
     * @param  Builder<TitleType>  $query
     * @return Builder<TitleType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<TitleType>  $query
     * @return Builder<TitleType>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('is_elmo_active', true);
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

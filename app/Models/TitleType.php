<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class TitleType extends Model
{
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('elmo_active', true);
    }

    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }
}

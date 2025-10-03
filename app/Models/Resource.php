<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'doi',
        'year',
        'resource_type_id',
        'version',
        'language_id',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** @return HasMany<ResourceTitle> */
    public function titles(): HasMany
    {
        return $this->hasMany(ResourceTitle::class);
    }

    /** @return BelongsToMany<License> */
    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class)->withTimestamps();
    }
}

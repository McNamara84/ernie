<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    use HasFactory;

    protected $fillable = [
        'doi',
        'year',
        'resource_type_id',
        'version',
        'language_id',
        'last_editor_id',
        'curation',
    ];

    protected $casts = [
        'curation' => 'boolean',
    ];

    public function titles(): HasMany
    {
        return $this->hasMany(Title::class);
    }

    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_editor_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResourceAuthor extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'authorable_id',
        'authorable_type',
        'position',
        'email',
        'website',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    /** @return BelongsTo<resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return MorphTo<Model, static> */
    public function authorable(): MorphTo
    {
        /** @var MorphTo<Model, static> $relation */
        $relation = $this->morphTo();

        return $relation;
    }

    /** @return BelongsToMany<Role, static> */
    public function roles(): BelongsToMany
    {
        /** @var BelongsToMany<Role, static> $relation */
        $relation = $this->belongsToMany(Role::class, 'resource_author_role');

        return $relation;
    }

    /** @return HasMany<Affiliation, static> */
    public function affiliations(): HasMany
    {
        /** @var HasMany<Affiliation, static> $relation */
        $relation = $this->hasMany(Affiliation::class);

        return $relation;
    }
}

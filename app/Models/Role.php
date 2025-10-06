<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    /** @return BelongsToMany<ResourceAuthor, static> */
    public function resourceAuthors(): BelongsToMany
    {
        /** @var BelongsToMany<ResourceAuthor, static> $relation */
        $relation = $this->belongsToMany(ResourceAuthor::class, 'author_role');

        return $relation;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Person extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $table = 'persons';

    protected $fillable = [
        'orcid',
        'first_name',
        'last_name',
    ];

    /** @return MorphMany<ResourceAuthor, static> */
    public function resourceAuthors(): MorphMany
    {
        /** @var MorphMany<ResourceAuthor, static> $relation */
        $relation = $this->morphMany(ResourceAuthor::class, 'authorable');

        return $relation;
    }
}

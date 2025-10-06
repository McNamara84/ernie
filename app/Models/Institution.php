<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Institution extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'name',
        'ror_id',
    ];

    /** @return MorphMany<ResourceAuthor, static> */
    public function resourceAuthors(): MorphMany
    {
        /** @var MorphMany<ResourceAuthor, static> $relation */
        $relation = $this->morphMany(ResourceAuthor::class, 'authorable');

        return $relation;
    }
}

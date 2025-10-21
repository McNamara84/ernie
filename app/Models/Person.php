<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property string|null $orcid
 * @property string|null $first_name
 * @property string|null $last_name
 * @property \Illuminate\Support\Carbon|null $orcid_verified_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Person extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $table = 'persons';

    protected $fillable = [
        'orcid',
        'first_name',
        'last_name',
        'orcid_verified_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orcid_verified_at' => 'datetime',
        ];
    }

    /** @return MorphMany<ResourceAuthor, static> */
    public function resourceAuthors(): MorphMany
    {
        /** @var MorphMany<ResourceAuthor, static> $relation */
        $relation = $this->morphMany(ResourceAuthor::class, 'authorable');

        return $relation;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    public const APPLIES_TO_AUTHOR = 'author';
    public const APPLIES_TO_CONTRIBUTOR_PERSON = 'contributor_person';
    public const APPLIES_TO_CONTRIBUTOR_INSTITUTION = 'contributor_institution';
    public const APPLIES_TO_CONTRIBUTOR_PERSON_AND_INSTITUTION = 'contributor_person_and_institution';

    protected $fillable = [
        'name',
        'slug',
        'applies_to',
        'is_active_in_ernie',
        'is_active_in_elmo',
    ];

    protected $casts = [
        'is_active_in_ernie' => 'boolean',
        'is_active_in_elmo' => 'boolean',
    ];

    /** @return BelongsToMany<ResourceAuthor, static> */
    public function resourceAuthors(): BelongsToMany
    {
        /** @var BelongsToMany<ResourceAuthor, static> $relation */
        $relation = $this->belongsToMany(ResourceAuthor::class, 'author_role');

        return $relation;
    }
}

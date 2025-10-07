<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Scope the query to roles that apply to authors.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeAuthors(Builder $query): Builder
    {
        return $query->where('applies_to', self::APPLIES_TO_AUTHOR);
    }

    /**
     * Scope the query to roles that apply to contributor people.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeContributorPersons(Builder $query): Builder
    {
        return $query->whereIn('applies_to', [
            self::APPLIES_TO_CONTRIBUTOR_PERSON,
            self::APPLIES_TO_CONTRIBUTOR_PERSON_AND_INSTITUTION,
        ]);
    }

    /**
     * Scope the query to roles that apply to contributor institutions.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeContributorInstitutions(Builder $query): Builder
    {
        return $query->whereIn('applies_to', [
            self::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
            self::APPLIES_TO_CONTRIBUTOR_PERSON_AND_INSTITUTION,
        ]);
    }

    /**
     * Scope the query to roles active in Ernie.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeActiveInErnie(Builder $query): Builder
    {
        return $query->where('is_active_in_ernie', true);
    }

    /**
     * Scope the query to roles active in ELMO.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeActiveInElmo(Builder $query): Builder
    {
        return $query->where('is_active_in_elmo', true);
    }

    /**
     * Scope the query to order roles by name.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /** @return BelongsToMany<ResourceAuthor, static> */
    public function resourceAuthors(): BelongsToMany
    {
        /** @var BelongsToMany<ResourceAuthor, static> $relation */
        $relation = $this->belongsToMany(ResourceAuthor::class, 'author_role');

        return $relation;
    }
}

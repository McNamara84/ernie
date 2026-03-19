<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Resource Contributor Model (DataCite #7)
 *
 * Links a Resource to its contributors (Persons or Institutions).
 *
 * @property int $id
 * @property int $resource_id
 * @property string $contributorable_type
 * @property int $contributorable_id
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read Person|Institution $contributorable
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ContributorType> $contributorTypes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Affiliation> $affiliations
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/contributor/
 */
#[Fillable(['resource_id', 'contributorable_type', 'contributorable_id', 'position'])]
class ResourceContributor extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $casts = [
        'position' => 'integer',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return MorphTo<Model, static> */
    public function contributorable(): MorphTo
    {
        /** @var MorphTo<Model, static> $relation */
        $relation = $this->morphTo();

        return $relation;
    }

    /** @return BelongsToMany<ContributorType, static> */
    public function contributorTypes(): BelongsToMany
    {
        /** @var BelongsToMany<ContributorType, static> $relation */
        $relation = $this->belongsToMany(
            ContributorType::class,
            'resource_contributor_contributor_type',
        )->withTimestamps();

        return $relation;
    }

    /** @return MorphMany<Affiliation, static> */
    public function affiliations(): MorphMany
    {
        /** @var MorphMany<Affiliation, static> $relation */
        $relation = $this->morphMany(Affiliation::class, 'affiliatable');

        return $relation;
    }

    /**
     * Check if the contributor is a Person.
     */
    public function isPerson(): bool
    {
        return $this->contributorable_type === Person::class;
    }

    /**
     * Check if the contributor is an Institution.
     */
    public function isInstitution(): bool
    {
        return $this->contributorable_type === Institution::class;
    }
}

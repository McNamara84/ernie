<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int $contributor_type_id
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read Person|Institution $contributorable
 * @property-read ContributorType $contributorType
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Affiliation> $affiliations
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/contributor/
 */
class ResourceContributor extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'contributorable_type',
        'contributorable_id',
        'contributor_type_id',
        'position',
    ];

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

    /** @return BelongsTo<ContributorType, static> */
    public function contributorType(): BelongsTo
    {
        /** @var BelongsTo<ContributorType, static> $relation */
        $relation = $this->belongsTo(ContributorType::class);

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

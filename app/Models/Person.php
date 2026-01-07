<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Person Model (DataCite Creator/Contributor)
 *
 * Represents a person who can be a creator or contributor to resources.
 * Uses DataCite naming: familyName, givenName.
 *
 * @property int $id
 * @property string|null $given_name
 * @property string|null $family_name
 * @property string|null $name_identifier
 * @property string|null $name_identifier_scheme
 * @property string|null $name_identifier_scheme_uri
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ResourceCreator> $resourceCreators
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ResourceContributor> $resourceContributors
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/creator/
 */
class Person extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $table = 'persons';

    protected $fillable = [
        'given_name',
        'family_name',
        'name_identifier',
        'name_identifier_scheme',
        'name_identifier_scheme_uri',
    ];

    /** @return MorphMany<ResourceCreator, static> */
    public function resourceCreators(): MorphMany
    {
        /** @var MorphMany<ResourceCreator, static> $relation */
        $relation = $this->morphMany(ResourceCreator::class, 'creatorable');

        return $relation;
    }

    /** @return MorphMany<ResourceContributor, static> */
    public function resourceContributors(): MorphMany
    {
        /** @var MorphMany<ResourceContributor, static> $relation */
        $relation = $this->morphMany(ResourceContributor::class, 'contributorable');

        return $relation;
    }

    /**
     * Get the full name in "Family, Given" format (DataCite convention).
     */
    public function getFullNameAttribute(): string
    {
        if ($this->family_name && $this->given_name) {
            return "{$this->family_name}, {$this->given_name}";
        }

        return $this->family_name ?? $this->given_name ?? '';
    }

    /**
     * Check if this person has an ORCID identifier.
     */
    public function hasOrcid(): bool
    {
        return $this->name_identifier_scheme === 'ORCID' && $this->name_identifier !== null;
    }

    /**
     * Get the ORCID if present.
     */
    public function getOrcidAttribute(): ?string
    {
        return $this->hasOrcid() ? $this->name_identifier : null;
    }
}

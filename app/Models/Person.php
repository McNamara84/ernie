<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

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
 * @property string|null $scheme_uri
 * @property Carbon|null $orcid_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ResourceCreator> $resourceCreators
 * @property-read Collection<int, ResourceContributor> $resourceContributors
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/
 */
#[Fillable(['given_name', 'family_name', 'name_identifier', 'name_identifier_scheme', 'scheme_uri'])]
#[Table('persons')]
class Person extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orcid_verified_at' => 'datetime',
        ];
    }

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

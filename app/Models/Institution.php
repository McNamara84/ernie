<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Institution Model (DataCite Creator/Contributor with nameType="Organizational")
 *
 * Represents an organization that can be a creator or contributor to resources.
 *
 * @property int $id
 * @property string $name
 * @property string|null $name_identifier
 * @property string|null $name_identifier_scheme
 * @property string|null $scheme_uri
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ResourceCreator> $resourceCreators
 * @property-read Collection<int, ResourceContributor> $resourceContributors
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/creator/
 */
#[Fillable(['name', 'name_identifier', 'name_identifier_scheme', 'scheme_uri'])]
class Institution extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

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
     * Check if this institution has a ROR identifier.
     */
    public function hasRor(): bool
    {
        return $this->name_identifier_scheme === 'ROR' && $this->name_identifier !== null;
    }

    /**
     * Check if this institution is an MSL Laboratory.
     */
    public function isLaboratory(): bool
    {
        return $this->name_identifier_scheme === 'labid';
    }

    /**
     * Get the ROR ID if present.
     */
    public function getRorIdAttribute(): ?string
    {
        return $this->hasRor() ? $this->name_identifier : null;
    }
}

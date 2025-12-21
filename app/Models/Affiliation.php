<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Affiliation Model (DataCite Creator/Contributor Affiliation)
 *
 * Stores affiliations for creators and contributors (polymorphic).
 *
 * @property int $id
 * @property string $affiliatable_type
 * @property int $affiliatable_id
 * @property string $name
 * @property string|null $identifier
 * @property string|null $identifier_scheme
 * @property string|null $scheme_uri
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read ResourceCreator|ResourceContributor $affiliatable
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/creator/#affiliation
 */
class Affiliation extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'affiliatable_type',
        'affiliatable_id',
        'name',
        'identifier',
        'identifier_scheme',
        'scheme_uri',
    ];

    /** @return MorphTo<Model, static> */
    public function affiliatable(): MorphTo
    {
        /** @var MorphTo<Model, static> $relation */
        $relation = $this->morphTo();

        return $relation;
    }

    /**
     * Check if this affiliation has a ROR identifier.
     */
    public function hasRor(): bool
    {
        return $this->identifier_scheme === 'ROR'
            && $this->identifier !== null;
    }

    /**
     * Get the ROR ID if present.
     */
    public function getRorIdAttribute(): ?string
    {
        return $this->hasRor() ? $this->identifier : null;
    }
}

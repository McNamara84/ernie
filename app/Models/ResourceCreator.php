<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Resource Creator Model (DataCite #2)
 *
 * Links a Resource to its creators (Persons or Institutions).
 *
 * @property int $id
 * @property int $resource_id
 * @property string $creatorable_type
 * @property int $creatorable_id
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Resource $resource
 * @property-read Person|Institution $creatorable
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Affiliation> $affiliations
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/creator/
 */
class ResourceCreator extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'creatorable_type',
        'creatorable_id',
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
    public function creatorable(): MorphTo
    {
        /** @var MorphTo<Model, static> $relation */
        $relation = $this->morphTo();

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
     * Check if the creator is a Person.
     */
    public function isPerson(): bool
    {
        return $this->creatorable_type === Person::class;
    }

    /**
     * Check if the creator is an Institution.
     */
    public function isInstitution(): bool
    {
        return $this->creatorable_type === Institution::class;
    }
}

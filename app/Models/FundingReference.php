<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FundingReference Model (DataCite #19)
 *
 * Stores funding information for a Resource.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $funder_name
 * @property string|null $funder_identifier
 * @property int|null $funder_identifier_type_id
 * @property string|null $funder_identifier_scheme_uri
 * @property string|null $award_number
 * @property string|null $award_uri
 * @property string|null $award_title
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read FunderIdentifierType|null $funderIdentifierType
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/fundingreference/
 */
class FundingReference extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'funder_name',
        'funder_identifier',
        'funder_identifier_type_id',
        'funder_identifier_scheme_uri',
        'award_number',
        'award_uri',
        'award_title',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return BelongsTo<FunderIdentifierType, static> */
    public function funderIdentifierType(): BelongsTo
    {
        /** @var BelongsTo<FunderIdentifierType, static> $relation */
        $relation = $this->belongsTo(FunderIdentifierType::class);

        return $relation;
    }

    /**
     * Check if this funding reference has an identifier.
     */
    public function hasFunderIdentifier(): bool
    {
        return $this->funder_identifier !== null;
    }

    /**
     * Check if this funding reference has award information.
     */
    public function hasAward(): bool
    {
        return $this->award_number !== null || $this->award_title !== null;
    }
}

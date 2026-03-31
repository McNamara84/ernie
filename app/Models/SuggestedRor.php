<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SuggestedRor Model
 *
 * Stores ROR-ID suggestions discovered via the ROR API for entities
 * (affiliations, institutions, funders) that lack a ROR identifier.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $entity_name
 * @property string $suggested_ror_id
 * @property string $suggested_name
 * @property float $similarity_score
 * @property array<int, string>|null $ror_aliases
 * @property string|null $existing_identifier
 * @property string|null $existing_identifier_type
 * @property Carbon $discovered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resource $resource
 */
#[Fillable([
    'resource_id',
    'entity_type',
    'entity_id',
    'entity_name',
    'suggested_ror_id',
    'suggested_name',
    'similarity_score',
    'ror_aliases',
    'existing_identifier',
    'existing_identifier_type',
    'discovered_at',
])]
class SuggestedRor extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'discovered_at' => 'datetime',
        'similarity_score' => 'float',
        'ror_aliases' => 'array',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /**
     * Get the underlying entity (Affiliation, Institution, or FundingReference).
     */
    public function entity(): Affiliation|Institution|FundingReference|null
    {
        return match ($this->entity_type) {
            'affiliation' => Affiliation::find($this->entity_id),
            'institution' => Institution::find($this->entity_id),
            'funder' => FundingReference::find($this->entity_id),
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SuggestedOrcid Model
 *
 * Stores ORCID suggestions discovered from the ORCID Public API
 * for persons (creators/contributors) who lack an ORCID identifier.
 *
 * @property int $id
 * @property int $resource_id
 * @property int $person_id
 * @property string $suggested_orcid
 * @property float $similarity_score
 * @property string|null $candidate_first_name
 * @property string|null $candidate_last_name
 * @property array<int, string>|null $candidate_affiliations
 * @property string $source_context
 * @property Carbon $discovered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read Person $person
 */
#[Fillable([
    'resource_id',
    'person_id',
    'suggested_orcid',
    'similarity_score',
    'candidate_first_name',
    'candidate_last_name',
    'candidate_affiliations',
    'source_context',
    'discovered_at',
])]
class SuggestedOrcid extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'discovered_at' => 'datetime',
        'similarity_score' => 'float',
        'candidate_affiliations' => 'array',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return BelongsTo<Person, static> */
    public function person(): BelongsTo
    {
        /** @var BelongsTo<Person, static> $relation */
        $relation = $this->belongsTo(Person::class);

        return $relation;
    }
}

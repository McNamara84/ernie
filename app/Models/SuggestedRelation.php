<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SuggestedRelation Model
 *
 * Stores suggested related identifiers discovered from external APIs
 * (ScholExplorer, DataCite Event Data) that curators can accept or decline.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $identifier
 * @property int $identifier_type_id
 * @property int $relation_type_id
 * @property string $source
 * @property string|null $source_title
 * @property string|null $source_type
 * @property string|null $source_publisher
 * @property string|null $source_publication_date
 * @property \Illuminate\Support\Carbon $discovered_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read IdentifierType $identifierType
 * @property-read RelationType $relationType
 */
#[Fillable([
    'resource_id',
    'identifier',
    'identifier_type_id',
    'relation_type_id',
    'source',
    'source_title',
    'source_type',
    'source_publisher',
    'source_publication_date',
    'discovered_at',
])]
class SuggestedRelation extends Model
{
    /** @var array<string, string> */
    protected $casts = [
        'discovered_at' => 'datetime',
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /** @return BelongsTo<IdentifierType, static> */
    public function identifierType(): BelongsTo
    {
        /** @var BelongsTo<IdentifierType, static> $relation */
        $relation = $this->belongsTo(IdentifierType::class);

        return $relation;
    }

    /** @return BelongsTo<RelationType, static> */
    public function relationType(): BelongsTo
    {
        /** @var BelongsTo<RelationType, static> $relation */
        $relation = $this->belongsTo(RelationType::class);

        return $relation;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RelatedIdentifier Model (DataCite #12)
 *
 * Stores related identifiers for a Resource.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $identifier
 * @property int $identifier_type_id
 * @property int $relation_type_id
 * @property string|null $resource_type_general
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read IdentifierType $identifierType
 * @property-read RelationType $relationType
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/relatedidentifier/
 */
class RelatedIdentifier extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'identifier',
        'identifier_type_id',
        'relation_type_id',
        'resource_type_general',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Bidirectional relation type pairs.
     *
     * @var array<string, string>
     */
    public const BIDIRECTIONAL_PAIRS = [
        'Cites' => 'IsCitedBy',
        'IsCitedBy' => 'Cites',
        'References' => 'IsReferencedBy',
        'IsReferencedBy' => 'References',
        'Documents' => 'IsDocumentedBy',
        'IsDocumentedBy' => 'Documents',
        'Describes' => 'IsDescribedBy',
        'IsDescribedBy' => 'Describes',
        'IsNewVersionOf' => 'IsPreviousVersionOf',
        'IsPreviousVersionOf' => 'IsNewVersionOf',
        'HasVersion' => 'IsVersionOf',
        'IsVersionOf' => 'HasVersion',
        'Continues' => 'IsContinuedBy',
        'IsContinuedBy' => 'Continues',
        'Obsoletes' => 'IsObsoletedBy',
        'IsObsoletedBy' => 'Obsoletes',
        'IsVariantFormOf' => 'IsOriginalFormOf',
        'IsOriginalFormOf' => 'IsVariantFormOf',
        'HasPart' => 'IsPartOf',
        'IsPartOf' => 'HasPart',
        'Compiles' => 'IsCompiledBy',
        'IsCompiledBy' => 'Compiles',
        'IsSourceOf' => 'IsDerivedFrom',
        'IsDerivedFrom' => 'IsSourceOf',
        'IsSupplementTo' => 'IsSupplementedBy',
        'IsSupplementedBy' => 'IsSupplementTo',
        'Requires' => 'IsRequiredBy',
        'IsRequiredBy' => 'Requires',
        'HasMetadata' => 'IsMetadataFor',
        'IsMetadataFor' => 'HasMetadata',
        'Reviews' => 'IsReviewedBy',
        'IsReviewedBy' => 'Reviews',
        'Collects' => 'IsCollectedBy',
        'IsCollectedBy' => 'Collects',
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
        $relation = $this->belongsTo(IdentifierType::class, 'identifier_type_id');

        return $relation;
    }

    /** @return BelongsTo<RelationType, static> */
    public function relationType(): BelongsTo
    {
        /** @var BelongsTo<RelationType, static> $relation */
        $relation = $this->belongsTo(RelationType::class);

        return $relation;
    }

    /**
     * Get the opposite relation type for bidirectional pairs.
     */
    public static function getOppositeRelationType(string $relationType): ?string
    {
        return self::BIDIRECTIONAL_PAIRS[$relationType] ?? null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelatedIdentifier extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'resource_related_identifiers';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'resource_id',
        'identifier',
        'identifier_type',
        'relation_type',
        'position',
        'related_title',
        'related_metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'related_metadata' => 'array',
        'position' => 'integer',
    ];

    /**
     * Supported identifier types based on DataCite Schema and metaworks analysis.
     *
     * @var array<string>
     */
    public const IDENTIFIER_TYPES = [
        'DOI',
        'URL',
        'Handle',
        'IGSN',
        'URN',
        'ISBN',
        'ISSN',
        'PURL',
        'ARK',
        'arXiv',
        'bibcode',
        'EAN13',
        'EISSN',
        'ISTC',
        'LISSN',
        'LSID',
        'PMID',
        'PURL',
        'UPC',
        'w3id',
    ];

    /**
     * DataCite 4.6 Relation Types grouped by category.
     *
     * @var array<string, array<string>>
     */
    public const RELATION_TYPES_GROUPED = [
        'Citation' => [
            'Cites',
            'IsCitedBy',
            'References',
            'IsReferencedBy',
        ],
        'Documentation' => [
            'Documents',
            'IsDocumentedBy',
            'Describes',
            'IsDescribedBy',
        ],
        'Versions' => [
            'IsNewVersionOf',
            'IsPreviousVersionOf',
            'HasVersion',
            'IsVersionOf',
            'Continues',
            'IsContinuedBy',
            'Obsoletes',
            'IsObsoletedBy',
            'IsVariantFormOf',
            'IsOriginalFormOf',
            'IsIdenticalTo',
        ],
        'Compilation' => [
            'HasPart',
            'IsPartOf',
            'Compiles',
            'IsCompiledBy',
        ],
        'Derivation' => [
            'IsSourceOf',
            'IsDerivedFrom',
        ],
        'Supplement' => [
            'IsSupplementTo',
            'IsSupplementedBy',
        ],
        'Software' => [
            'Requires',
            'IsRequiredBy',
        ],
        'Metadata' => [
            'HasMetadata',
            'IsMetadataFor',
        ],
        'Reviews' => [
            'Reviews',
            'IsReviewedBy',
        ],
        'Other' => [
            'IsPublishedIn',
            'Collects',
            'IsCollectedBy',
        ],
    ];

    /**
     * Most commonly used relation types (Top 10 from metaworks analysis).
     *
     * @var array<string>
     */
    public const MOST_USED_RELATION_TYPES = [
        'Cites',           // 56.1%
        'References',      // 14.7%
        'IsDerivedFrom',   // 12.6%
        'IsDocumentedBy',  // 5.2%
        'IsSupplementTo',  // 3.7%
        'Compiles',        // 2.5%
        'HasPart',         // 1.2%
        'IsPartOf',        // 0.8%
        'IsCitedBy',       // 0.6%
        'IsVariantFormOf', // 0.6%
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

    /**
     * Get all relation types as flat array.
     *
     * @return array<string>
     */
    public static function getAllRelationTypes(): array
    {
        $types = [];
        foreach (self::RELATION_TYPES_GROUPED as $group) {
            $types = array_merge($types, $group);
        }
        return array_unique($types);
    }

    /**
     * Get the opposite relation type for bidirectional pairs.
     *
     * @param string $relationType
     * @return string|null
     */
    public static function getOppositeRelationType(string $relationType): ?string
    {
        return self::BIDIRECTIONAL_PAIRS[$relationType] ?? null;
    }

    /**
     * Get the resource that owns this related identifier.
     *
     * @return BelongsTo<Resource, self>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}


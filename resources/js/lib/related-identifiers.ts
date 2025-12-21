import type { RelationType } from '@/types';

/**
 * DataCite 4.6 Relation Types grouped by category.
 * Based on analysis of metaworks database and DataCite schema.
 */
export const RELATION_TYPES_GROUPED: Record<string, RelationType[]> = {
    Citation: ['Cites', 'IsCitedBy', 'References', 'IsReferencedBy'],
    Documentation: ['Documents', 'IsDocumentedBy', 'Describes', 'IsDescribedBy'],
    Versions: [
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
    Compilation: ['HasPart', 'IsPartOf', 'Compiles', 'IsCompiledBy'],
    Derivation: ['IsSourceOf', 'IsDerivedFrom'],
    Supplement: ['IsSupplementTo', 'IsSupplementedBy'],
    Software: ['Requires', 'IsRequiredBy'],
    Metadata: ['HasMetadata', 'IsMetadataFor'],
    Reviews: ['Reviews', 'IsReviewedBy'],
    Other: ['IsPublishedIn', 'Collects', 'IsCollectedBy'],
};

/**
 * Most commonly used relation types based on metaworks database analysis.
 *
 * Top 10 represent 95.4% of all relations:
 * - Cites: 56.1%
 * - References: 14.7%
 * - IsDerivedFrom: 12.6%
 * - IsDocumentedBy: 5.2%
 * - IsSupplementTo: 3.7%
 * - Compiles: 2.5%
 * - HasPart: 1.2%
 * - IsPartOf: 0.8%
 * - IsCitedBy: 0.6%
 * - IsVariantFormOf: 0.6%
 *
 * Version relations (IsNewVersionOf, IsPreviousVersionOf) added for easy access
 * in simple mode as they are commonly needed for dataset version management.
 */
export const MOST_USED_RELATION_TYPES: RelationType[] = [
    'Cites',
    'References',
    'IsDerivedFrom',
    'IsDocumentedBy',
    'IsSupplementTo',
    'IsNewVersionOf',
    'IsPreviousVersionOf',
];

/**
 * Bidirectional relation type pairs for suggestions.
 * When user selects one type, we can suggest its opposite.
 */
export const BIDIRECTIONAL_PAIRS: Record<RelationType, RelationType> = {
    Cites: 'IsCitedBy',
    IsCitedBy: 'Cites',
    References: 'IsReferencedBy',
    IsReferencedBy: 'References',
    Documents: 'IsDocumentedBy',
    IsDocumentedBy: 'Documents',
    Describes: 'IsDescribedBy',
    IsDescribedBy: 'Describes',
    IsNewVersionOf: 'IsPreviousVersionOf',
    IsPreviousVersionOf: 'IsNewVersionOf',
    HasVersion: 'IsVersionOf',
    IsVersionOf: 'HasVersion',
    Continues: 'IsContinuedBy',
    IsContinuedBy: 'Continues',
    Obsoletes: 'IsObsoletedBy',
    IsObsoletedBy: 'Obsoletes',
    IsVariantFormOf: 'IsOriginalFormOf',
    IsOriginalFormOf: 'IsVariantFormOf',
    HasPart: 'IsPartOf',
    IsPartOf: 'HasPart',
    Compiles: 'IsCompiledBy',
    IsCompiledBy: 'Compiles',
    IsSourceOf: 'IsDerivedFrom',
    IsDerivedFrom: 'IsSourceOf',
    IsSupplementTo: 'IsSupplementedBy',
    IsSupplementedBy: 'IsSupplementTo',
    Requires: 'IsRequiredBy',
    IsRequiredBy: 'Requires',
    HasMetadata: 'IsMetadataFor',
    IsMetadataFor: 'HasMetadata',
    Reviews: 'IsReviewedBy',
    IsReviewedBy: 'Reviews',
    Collects: 'IsCollectedBy',
    IsCollectedBy: 'Collects',
    // Unidirectional
    IsPublishedIn: 'IsPublishedIn' as RelationType,
    IsIdenticalTo: 'IsIdenticalTo' as RelationType,
};

/**
 * Get the opposite relation type for bidirectional pairs.
 */
export function getOppositeRelationType(relationType: RelationType): RelationType | null {
    const opposite = BIDIRECTIONAL_PAIRS[relationType];
    // Don't return the same type (unidirectional)
    return opposite !== relationType ? opposite : null;
}

/**
 * Get all relation types as a flat array.
 */
export function getAllRelationTypes(): RelationType[] {
    return Object.values(RELATION_TYPES_GROUPED).flat();
}

/**
 * Descriptions for relation types (for tooltips).
 */
export const RELATION_TYPE_DESCRIPTIONS: Record<RelationType, string> = {
    Cites: 'This resource cites a publication or resource',
    IsCitedBy: 'This resource is cited by a publication',
    References: 'This resource references another resource',
    IsReferencedBy: 'This resource is referenced by another resource',
    Documents: 'This resource documents another resource',
    IsDocumentedBy: 'This resource is documented by another resource',
    Describes: 'This resource describes another resource',
    IsDescribedBy: 'This resource is described by another resource',
    IsNewVersionOf: 'This resource is a new version of',
    IsPreviousVersionOf: 'This resource is a previous version of',
    HasVersion: 'This resource has a versioned instance',
    IsVersionOf: 'This resource is an instance of',
    Continues: 'This resource continues',
    IsContinuedBy: 'This resource is continued by',
    Obsoletes: 'This resource replaces',
    IsObsoletedBy: 'This resource is replaced by',
    IsVariantFormOf: 'This resource is a variant of',
    IsOriginalFormOf: 'This resource is the original form of',
    IsIdenticalTo: 'This resource is identical to',
    HasPart: 'This resource has as part',
    IsPartOf: 'This resource is part of',
    Compiles: 'This resource compiles',
    IsCompiledBy: 'This resource is compiled in',
    IsSourceOf: 'This resource is the source of',
    IsDerivedFrom: 'This resource is derived from',
    IsSupplementTo: 'This resource is supplement to',
    IsSupplementedBy: 'This resource has as supplement',
    Requires: 'This resource requires',
    IsRequiredBy: 'This resource is required by',
    HasMetadata: 'Metadata for this resource is located at',
    IsMetadataFor: 'This resource contains metadata for',
    Reviews: 'This resource reviews',
    IsReviewedBy: 'This resource is reviewed in',
    IsPublishedIn: 'This resource is published in',
    Collects: 'This resource collects',
    IsCollectedBy: 'This resource is collected by',
};

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
 */
export const MOST_USED_RELATION_TYPES: RelationType[] = [
    'Cites',
    'References',
    'IsDerivedFrom',
    'IsDocumentedBy',
    'IsSupplementTo',
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
 * German descriptions for relation types (for tooltips).
 */
export const RELATION_TYPE_DESCRIPTIONS: Record<RelationType, string> = {
    Cites: 'Dieser Datensatz zitiert eine Publikation oder Ressource',
    IsCitedBy: 'Dieser Datensatz wird von einer Publikation zitiert',
    References: 'Dieser Datensatz referenziert eine andere Ressource',
    IsReferencedBy: 'Dieser Datensatz wird von einer anderen Ressource referenziert',
    Documents: 'Dieser Datensatz dokumentiert eine andere Ressource',
    IsDocumentedBy: 'Dieser Datensatz wird von einer anderen Ressource dokumentiert',
    Describes: 'Dieser Datensatz beschreibt eine andere Ressource',
    IsDescribedBy: 'Dieser Datensatz wird von einer anderen Ressource beschrieben',
    IsNewVersionOf: 'Dieser Datensatz ist eine neue Version von',
    IsPreviousVersionOf: 'Dieser Datensatz ist eine vorherige Version von',
    HasVersion: 'Dieser Datensatz hat eine versionierte Instanz',
    IsVersionOf: 'Dieser Datensatz ist eine Instanz von',
    Continues: 'Dieser Datensatz setzt fort',
    IsContinuedBy: 'Dieser Datensatz wird fortgesetzt von',
    Obsoletes: 'Dieser Datensatz ersetzt',
    IsObsoletedBy: 'Dieser Datensatz wird ersetzt von',
    IsVariantFormOf: 'Dieser Datensatz ist eine Variante von',
    IsOriginalFormOf: 'Dieser Datensatz ist die Originalform von',
    IsIdenticalTo: 'Dieser Datensatz ist identisch zu',
    HasPart: 'Dieser Datensatz hat als Teil',
    IsPartOf: 'Dieser Datensatz ist Teil von',
    Compiles: 'Dieser Datensatz kompiliert',
    IsCompiledBy: 'Dieser Datensatz ist kompiliert in',
    IsSourceOf: 'Dieser Datensatz ist die Quelle von',
    IsDerivedFrom: 'Dieser Datensatz ist abgeleitet von',
    IsSupplementTo: 'Dieser Datensatz ist Supplement zu',
    IsSupplementedBy: 'Dieser Datensatz hat als Supplement',
    Requires: 'Dieser Datensatz benötigt',
    IsRequiredBy: 'Dieser Datensatz wird benötigt von',
    HasMetadata: 'Metadaten für diesen Datensatz befinden sich unter',
    IsMetadataFor: 'Dieser Datensatz enthält Metadaten für',
    Reviews: 'Dieser Datensatz rezensiert',
    IsReviewedBy: 'Dieser Datensatz wird rezensiert in',
    IsPublishedIn: 'Dieser Datensatz ist publiziert in',
    Collects: 'Dieser Datensatz sammelt',
    IsCollectedBy: 'Dieser Datensatz ist gesammelt von',
};

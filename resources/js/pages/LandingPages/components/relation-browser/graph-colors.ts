import type { GraphNodeType } from './graph-types';

const GFZ_BLUE = '#0C2A63';
const CREATOR_COLOR = '#D946EF';
const CONTRIBUTOR_COLOR = '#FB923C';
const INSTITUTION_COLOR = '#14B8A6';

const CENTRAL_RADIUS = 30;
const NODE_RADIUS = 22;
const CREATOR_RADIUS = 16;
const INSTITUTION_RADIUS = 15;
const CONTRIBUTOR_RADIUS = 13;

const NODE_COLOR_MAP: Record<string, string> = {
    DOI: '#10B981',
    URL: '#0EA5E9',
    Handle: '#F59E0B',
    arXiv: '#F43F5E',
    IGSN: '#8B5CF6',
    ISBN: '#F97316',
    ISSN: '#84CC16',
    URN: '#EC4899',
    RAiD: '#06B6D4',
    Creator: CREATOR_COLOR,
    Contributor: CONTRIBUTOR_COLOR,
    Institution: INSTITUTION_COLOR,
};

const NODE_FALLBACK_COLOR = '#64748B';

const RELATION_TYPE_CATEGORIES: Record<string, string[]> = {
    Citation: ['Cites', 'IsCitedBy', 'References', 'IsReferencedBy'],
    Documentation: ['Describes', 'IsDescribedBy', 'IsDocumentedBy', 'Documents'],
    Version: [
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
    Composition: ['HasPart', 'IsPartOf', 'Compiles', 'IsCompiledBy'],
    Derivation: ['IsDerivedFrom', 'IsSourceOf'],
    Supplement: ['IsSupplementTo', 'IsSupplementedBy'],
    Software: ['Requires', 'IsRequiredBy'],
    Metadata: ['HasMetadata', 'IsMetadataFor'],
    Review: ['Reviews', 'IsReviewedBy'],
    Creator: ['Created'],
    Contributor: [
        'ContactPerson', 'DataCollector', 'DataCurator', 'DataManager',
        'Distributor', 'Editor', 'HostingInstitution', 'Producer',
        'ProjectLeader', 'ProjectManager', 'ProjectMember',
        'RegistrationAgency', 'RegistrationAuthority', 'RelatedPerson',
        'Researcher', 'ResearchGroup', 'RightsHolder', 'Sponsor',
        'Supervisor', 'WorkPackageLeader',
    ],
    Affiliation: ['AffiliatedWith'],
};

const EDGE_CATEGORY_COLOR_MAP: Record<string, string> = {
    Citation: '#6366F1',
    Documentation: '#06B6D4',
    Version: '#7C3AED',
    Composition: '#84CC16',
    Derivation: '#E11D48',
    Supplement: '#D97706',
    Software: '#C026D3',
    Metadata: '#475569',
    Review: '#059669',
    Creator: CREATOR_COLOR,
    Contributor: CONTRIBUTOR_COLOR,
    Affiliation: INSTITUTION_COLOR,
};

const EDGE_FALLBACK_COLOR = '#6B7280';

const relationTypeToCategoryCache = new Map<string, string>();

function getRelationCategory(relationType: string): string | null {
    if (relationTypeToCategoryCache.has(relationType)) {
        return relationTypeToCategoryCache.get(relationType)!;
    }
    for (const [category, types] of Object.entries(RELATION_TYPE_CATEGORIES)) {
        if (types.includes(relationType)) {
            relationTypeToCategoryCache.set(relationType, category);
            return category;
        }
    }
    return null;
}

export function getNodeColor(identifierType: string, isCentral: boolean): string {
    if (isCentral) {
        return GFZ_BLUE;
    }
    return NODE_COLOR_MAP[identifierType] ?? NODE_FALLBACK_COLOR;
}

export function getEdgeColor(relationType: string): string {
    const category = getRelationCategory(relationType);
    if (category) {
        return EDGE_CATEGORY_COLOR_MAP[category];
    }
    return EDGE_FALLBACK_COLOR;
}

export function getEdgeCategory(relationType: string): string {
    return getRelationCategory(relationType) ?? 'Other';
}

export function getNodeColorMap(): Record<string, string> {
    return { ...NODE_COLOR_MAP };
}

export function getEdgeCategoryColorMap(): Record<string, string> {
    return { ...EDGE_CATEGORY_COLOR_MAP };
}

export function getNodeRadius(nodeType: GraphNodeType, isCentral: boolean): number {
    if (isCentral) return CENTRAL_RADIUS;
    if (nodeType === 'creator') return CREATOR_RADIUS;
    if (nodeType === 'institution') return INSTITUTION_RADIUS;
    if (nodeType === 'contributor') return CONTRIBUTOR_RADIUS;
    return NODE_RADIUS;
}

export { GFZ_BLUE, CREATOR_COLOR, CONTRIBUTOR_COLOR, INSTITUTION_COLOR, NODE_FALLBACK_COLOR, EDGE_FALLBACK_COLOR, CENTRAL_RADIUS, NODE_RADIUS, CREATOR_RADIUS, INSTITUTION_RADIUS, CONTRIBUTOR_RADIUS };

import { describe, expect, it } from 'vitest';

import {
    CENTRAL_RADIUS,
    CONTRIBUTOR_COLOR,
    CONTRIBUTOR_RADIUS,
    CREATOR_COLOR,
    CREATOR_RADIUS,
    EDGE_FALLBACK_COLOR,
    getEdgeCategory,
    getEdgeCategoryColorMap,
    getEdgeColor,
    getNodeColor,
    getNodeColorMap,
    getNodeRadius,
    GFZ_BLUE,
    INSTITUTION_COLOR,
    INSTITUTION_RADIUS,
    NODE_FALLBACK_COLOR,
    NODE_RADIUS,
} from '@/pages/LandingPages/components/relation-browser/graph-colors';

describe('graph-colors', () => {
    describe('getNodeColor', () => {
        it('returns GFZ blue for central node', () => {
            expect(getNodeColor('DOI', true)).toBe(GFZ_BLUE);
            expect(getNodeColor('URL', true)).toBe(GFZ_BLUE);
            expect(getNodeColor('unknown', true)).toBe(GFZ_BLUE);
        });

        it('returns correct color for each known identifier type', () => {
            expect(getNodeColor('DOI', false)).toBe('#10B981');
            expect(getNodeColor('URL', false)).toBe('#0EA5E9');
            expect(getNodeColor('Handle', false)).toBe('#F59E0B');
            expect(getNodeColor('arXiv', false)).toBe('#F43F5E');
            expect(getNodeColor('IGSN', false)).toBe('#8B5CF6');
            expect(getNodeColor('ISBN', false)).toBe('#F97316');
            expect(getNodeColor('ISSN', false)).toBe('#84CC16');
            expect(getNodeColor('URN', false)).toBe('#EC4899');
            expect(getNodeColor('RAiD', false)).toBe('#06B6D4');
        });

        it('returns fallback color for unknown identifier types', () => {
            expect(getNodeColor('PMID', false)).toBe(NODE_FALLBACK_COLOR);
            expect(getNodeColor('EAN13', false)).toBe(NODE_FALLBACK_COLOR);
            expect(getNodeColor('unknown', false)).toBe(NODE_FALLBACK_COLOR);
        });

        it('never returns GFZ blue for non-central nodes', () => {
            const colorMap = getNodeColorMap();
            for (const color of Object.values(colorMap)) {
                expect(color).not.toBe(GFZ_BLUE);
            }
            expect(NODE_FALLBACK_COLOR).not.toBe(GFZ_BLUE);
        });
    });

    describe('getEdgeColor', () => {
        it('returns correct color for citation relation types', () => {
            expect(getEdgeColor('Cites')).toBe('#6366F1');
            expect(getEdgeColor('IsCitedBy')).toBe('#6366F1');
            expect(getEdgeColor('References')).toBe('#6366F1');
            expect(getEdgeColor('IsReferencedBy')).toBe('#6366F1');
        });

        it('returns correct color for documentation relation types', () => {
            expect(getEdgeColor('Describes')).toBe('#06B6D4');
            expect(getEdgeColor('IsDocumentedBy')).toBe('#06B6D4');
        });

        it('returns correct color for version relation types', () => {
            expect(getEdgeColor('IsNewVersionOf')).toBe('#7C3AED');
            expect(getEdgeColor('HasVersion')).toBe('#7C3AED');
        });

        it('returns correct color for composition relation types', () => {
            expect(getEdgeColor('HasPart')).toBe('#84CC16');
            expect(getEdgeColor('IsPartOf')).toBe('#84CC16');
        });

        it('returns correct color for derivation relation types', () => {
            expect(getEdgeColor('IsDerivedFrom')).toBe('#E11D48');
            expect(getEdgeColor('IsSourceOf')).toBe('#E11D48');
        });

        it('returns correct color for supplement relation types', () => {
            expect(getEdgeColor('IsSupplementTo')).toBe('#D97706');
            expect(getEdgeColor('IsSupplementedBy')).toBe('#D97706');
        });

        it('returns correct color for software relation types', () => {
            expect(getEdgeColor('Requires')).toBe('#C026D3');
            expect(getEdgeColor('IsRequiredBy')).toBe('#C026D3');
        });

        it('returns correct color for metadata relation types', () => {
            expect(getEdgeColor('HasMetadata')).toBe('#475569');
            expect(getEdgeColor('IsMetadataFor')).toBe('#475569');
        });

        it('returns correct color for review relation types', () => {
            expect(getEdgeColor('Reviews')).toBe('#059669');
            expect(getEdgeColor('IsReviewedBy')).toBe('#059669');
        });

        it('returns fallback color for unknown relation types', () => {
            expect(getEdgeColor('Other')).toBe(EDGE_FALLBACK_COLOR);
            expect(getEdgeColor('Collects')).toBe(EDGE_FALLBACK_COLOR);
            expect(getEdgeColor('IsPublishedIn')).toBe(EDGE_FALLBACK_COLOR);
            expect(getEdgeColor('unknown')).toBe(EDGE_FALLBACK_COLOR);
        });
    });

    describe('getEdgeCategory', () => {
        it('returns correct category for known relation types', () => {
            expect(getEdgeCategory('Cites')).toBe('Citation');
            expect(getEdgeCategory('Describes')).toBe('Documentation');
            expect(getEdgeCategory('HasVersion')).toBe('Version');
            expect(getEdgeCategory('HasPart')).toBe('Composition');
            expect(getEdgeCategory('IsDerivedFrom')).toBe('Derivation');
            expect(getEdgeCategory('IsSupplementTo')).toBe('Supplement');
            expect(getEdgeCategory('Requires')).toBe('Software');
            expect(getEdgeCategory('HasMetadata')).toBe('Metadata');
            expect(getEdgeCategory('Reviews')).toBe('Review');
        });

        it('returns "Other" for unknown relation types', () => {
            expect(getEdgeCategory('Other')).toBe('Other');
            expect(getEdgeCategory('Collects')).toBe('Other');
            expect(getEdgeCategory('unknown')).toBe('Other');
        });
    });

    describe('getNodeColorMap', () => {
        it('returns a copy of the node color map', () => {
            const map1 = getNodeColorMap();
            const map2 = getNodeColorMap();
            expect(map1).toEqual(map2);
            map1.DOI = '#000000';
            expect(getNodeColorMap().DOI).toBe('#10B981');
        });
    });

    describe('getEdgeCategoryColorMap', () => {
        it('returns a copy of the edge category color map', () => {
            const map1 = getEdgeCategoryColorMap();
            const map2 = getEdgeCategoryColorMap();
            expect(map1).toEqual(map2);
            map1.Citation = '#000000';
            expect(getEdgeCategoryColorMap().Citation).toBe('#6366F1');
        });
    });

    describe('Creator support', () => {
        it('returns Creator color for Creator identifier type', () => {
            expect(getNodeColor('Creator', false)).toBe(CREATOR_COLOR);
        });

        it('returns correct edge color for Created relation type', () => {
            expect(getEdgeColor('Created')).toBe(CREATOR_COLOR);
        });

        it('returns Creator category for Created relation type', () => {
            expect(getEdgeCategory('Created')).toBe('Creator');
        });

        it('includes Creator in edge category color map', () => {
            const map = getEdgeCategoryColorMap();
            expect(map.Creator).toBe(CREATOR_COLOR);
        });
    });

    describe('Contributor support', () => {
        it('returns Contributor color for Contributor identifier type', () => {
            expect(getNodeColor('Contributor', false)).toBe(CONTRIBUTOR_COLOR);
        });

        it('returns correct edge color for contributor relation types', () => {
            expect(getEdgeColor('Editor')).toBe(CONTRIBUTOR_COLOR);
            expect(getEdgeColor('DataCollector')).toBe(CONTRIBUTOR_COLOR);
            expect(getEdgeColor('HostingInstitution')).toBe(CONTRIBUTOR_COLOR);
        });

        it('returns Contributor category for contributor relation types', () => {
            expect(getEdgeCategory('Editor')).toBe('Contributor');
            expect(getEdgeCategory('DataCollector')).toBe('Contributor');
            expect(getEdgeCategory('ContactPerson')).toBe('Contributor');
            expect(getEdgeCategory('Supervisor')).toBe('Contributor');
        });

        it('includes Contributor in edge category color map', () => {
            const map = getEdgeCategoryColorMap();
            expect(map.Contributor).toBe(CONTRIBUTOR_COLOR);
        });
    });

    describe('getNodeRadius', () => {
        it('returns CENTRAL_RADIUS for central nodes', () => {
            expect(getNodeRadius('resource', true)).toBe(CENTRAL_RADIUS);
            expect(getNodeRadius('creator', true)).toBe(CENTRAL_RADIUS);
        });

        it('returns CREATOR_RADIUS for creator nodes', () => {
            expect(getNodeRadius('creator', false)).toBe(CREATOR_RADIUS);
        });

        it('returns CONTRIBUTOR_RADIUS for contributor nodes', () => {
            expect(getNodeRadius('contributor', false)).toBe(CONTRIBUTOR_RADIUS);
        });

        it('returns NODE_RADIUS for resource nodes', () => {
            expect(getNodeRadius('resource', false)).toBe(NODE_RADIUS);
        });

        it('has correct radius hierarchy: central > resource > creator > contributor', () => {
            expect(CENTRAL_RADIUS).toBeGreaterThan(NODE_RADIUS);
            expect(NODE_RADIUS).toBeGreaterThan(CREATOR_RADIUS);
            expect(CREATOR_RADIUS).toBeGreaterThan(CONTRIBUTOR_RADIUS);
        });

        it('returns INSTITUTION_RADIUS for institution nodes', () => {
            expect(getNodeRadius('institution', false)).toBe(INSTITUTION_RADIUS);
        });

        it('has institution radius between creator and resource radius', () => {
            expect(INSTITUTION_RADIUS).toBeGreaterThan(CONTRIBUTOR_RADIUS);
            expect(INSTITUTION_RADIUS).toBeLessThanOrEqual(NODE_RADIUS);
        });
    });

    describe('Institution support', () => {
        it('returns Institution color for Institution identifier type', () => {
            expect(getNodeColor('Institution', false)).toBe(INSTITUTION_COLOR);
        });

        it('returns correct edge color for AffiliatedWith relation type', () => {
            expect(getEdgeColor('AffiliatedWith')).toBe(INSTITUTION_COLOR);
        });

        it('returns Affiliation category for AffiliatedWith relation type', () => {
            expect(getEdgeCategory('AffiliatedWith')).toBe('Affiliation');
        });

        it('includes Affiliation in edge category color map', () => {
            const map = getEdgeCategoryColorMap();
            expect(map.Affiliation).toBe(INSTITUTION_COLOR);
        });

        it('INSTITUTION_COLOR is teal (#14B8A6)', () => {
            expect(INSTITUTION_COLOR).toBe('#14B8A6');
        });
    });
});

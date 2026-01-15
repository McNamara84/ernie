import { describe, expect, it } from 'vitest';

import {
    BIDIRECTIONAL_PAIRS,
    getAllRelationTypes,
    getOppositeRelationType,
    MOST_USED_RELATION_TYPES,
    RELATION_TYPE_DESCRIPTIONS,
    RELATION_TYPES_GROUPED,
} from '@/lib/related-identifiers';
import type { RelationType } from '@/types';

describe('related-identifiers', () => {
    describe('RELATION_TYPES_GROUPED', () => {
        it('contains all expected categories', () => {
            const categories = Object.keys(RELATION_TYPES_GROUPED);

            expect(categories).toContain('Citation');
            expect(categories).toContain('Documentation');
            expect(categories).toContain('Versions');
            expect(categories).toContain('Compilation');
            expect(categories).toContain('Derivation');
            expect(categories).toContain('Supplement');
            expect(categories).toContain('Software');
            expect(categories).toContain('Metadata');
            expect(categories).toContain('Reviews');
            expect(categories).toContain('Other');
        });

        it('contains Cites in Citation category', () => {
            expect(RELATION_TYPES_GROUPED.Citation).toContain('Cites');
            expect(RELATION_TYPES_GROUPED.Citation).toContain('IsCitedBy');
            expect(RELATION_TYPES_GROUPED.Citation).toContain('References');
            expect(RELATION_TYPES_GROUPED.Citation).toContain('IsReferencedBy');
        });

        it('contains version-related types in Versions category', () => {
            expect(RELATION_TYPES_GROUPED.Versions).toContain('IsNewVersionOf');
            expect(RELATION_TYPES_GROUPED.Versions).toContain('IsPreviousVersionOf');
            expect(RELATION_TYPES_GROUPED.Versions).toContain('HasVersion');
            expect(RELATION_TYPES_GROUPED.Versions).toContain('IsVersionOf');
        });

        it('contains compilation types in Compilation category', () => {
            expect(RELATION_TYPES_GROUPED.Compilation).toContain('HasPart');
            expect(RELATION_TYPES_GROUPED.Compilation).toContain('IsPartOf');
            expect(RELATION_TYPES_GROUPED.Compilation).toContain('Compiles');
            expect(RELATION_TYPES_GROUPED.Compilation).toContain('IsCompiledBy');
        });
    });

    describe('MOST_USED_RELATION_TYPES', () => {
        it('contains the most commonly used relation types', () => {
            expect(MOST_USED_RELATION_TYPES).toContain('Cites');
            expect(MOST_USED_RELATION_TYPES).toContain('References');
            expect(MOST_USED_RELATION_TYPES).toContain('IsDerivedFrom');
        });

        it('includes version relations for easy access', () => {
            expect(MOST_USED_RELATION_TYPES).toContain('IsNewVersionOf');
            expect(MOST_USED_RELATION_TYPES).toContain('IsPreviousVersionOf');
        });

        it('has a reasonable number of entries (not too many)', () => {
            expect(MOST_USED_RELATION_TYPES.length).toBeLessThanOrEqual(10);
            expect(MOST_USED_RELATION_TYPES.length).toBeGreaterThanOrEqual(5);
        });
    });

    describe('BIDIRECTIONAL_PAIRS', () => {
        it('maps Cites to IsCitedBy and vice versa', () => {
            expect(BIDIRECTIONAL_PAIRS['Cites']).toBe('IsCitedBy');
            expect(BIDIRECTIONAL_PAIRS['IsCitedBy']).toBe('Cites');
        });

        it('maps version relations correctly', () => {
            expect(BIDIRECTIONAL_PAIRS['IsNewVersionOf']).toBe('IsPreviousVersionOf');
            expect(BIDIRECTIONAL_PAIRS['IsPreviousVersionOf']).toBe('IsNewVersionOf');
        });

        it('maps HasPart to IsPartOf and vice versa', () => {
            expect(BIDIRECTIONAL_PAIRS['HasPart']).toBe('IsPartOf');
            expect(BIDIRECTIONAL_PAIRS['IsPartOf']).toBe('HasPart');
        });

        it('maps unidirectional relations to themselves', () => {
            expect(BIDIRECTIONAL_PAIRS['IsPublishedIn']).toBe('IsPublishedIn');
            expect(BIDIRECTIONAL_PAIRS['IsIdenticalTo']).toBe('IsIdenticalTo');
        });
    });

    describe('getOppositeRelationType', () => {
        it('returns the opposite for bidirectional pairs', () => {
            expect(getOppositeRelationType('Cites' as RelationType)).toBe('IsCitedBy');
            expect(getOppositeRelationType('IsCitedBy' as RelationType)).toBe('Cites');
        });

        it('returns null for unidirectional relations', () => {
            expect(getOppositeRelationType('IsPublishedIn' as RelationType)).toBeNull();
            expect(getOppositeRelationType('IsIdenticalTo' as RelationType)).toBeNull();
        });

        it('returns opposite for version relations', () => {
            expect(getOppositeRelationType('IsNewVersionOf' as RelationType)).toBe('IsPreviousVersionOf');
            expect(getOppositeRelationType('IsPreviousVersionOf' as RelationType)).toBe('IsNewVersionOf');
        });

        it('returns opposite for supplement relations', () => {
            expect(getOppositeRelationType('IsSupplementTo' as RelationType)).toBe('IsSupplementedBy');
            expect(getOppositeRelationType('IsSupplementedBy' as RelationType)).toBe('IsSupplementTo');
        });
    });

    describe('getAllRelationTypes', () => {
        it('returns a flat array of all relation types', () => {
            const allTypes = getAllRelationTypes();

            expect(Array.isArray(allTypes)).toBe(true);
            expect(allTypes.length).toBeGreaterThan(0);
        });

        it('includes types from all categories', () => {
            const allTypes = getAllRelationTypes();

            // From Citation
            expect(allTypes).toContain('Cites');
            // From Versions
            expect(allTypes).toContain('IsNewVersionOf');
            // From Derivation
            expect(allTypes).toContain('IsDerivedFrom');
            // From Other
            expect(allTypes).toContain('IsPublishedIn');
        });

        it('does not include duplicates', () => {
            const allTypes = getAllRelationTypes();
            const uniqueTypes = new Set(allTypes);

            expect(allTypes.length).toBe(uniqueTypes.size);
        });
    });

    describe('RELATION_TYPE_DESCRIPTIONS', () => {
        it('has descriptions for all common relation types', () => {
            expect(RELATION_TYPE_DESCRIPTIONS['Cites']).toBeDefined();
            expect(RELATION_TYPE_DESCRIPTIONS['IsCitedBy']).toBeDefined();
            expect(RELATION_TYPE_DESCRIPTIONS['IsNewVersionOf']).toBeDefined();
            expect(RELATION_TYPE_DESCRIPTIONS['IsDerivedFrom']).toBeDefined();
        });

        it('descriptions are non-empty strings', () => {
            const allDescriptions = Object.values(RELATION_TYPE_DESCRIPTIONS);

            allDescriptions.forEach((description) => {
                expect(typeof description).toBe('string');
                expect(description.length).toBeGreaterThan(0);
            });
        });

        it('has a description for each relation type', () => {
            const allTypes = getAllRelationTypes();

            allTypes.forEach((type) => {
                expect(RELATION_TYPE_DESCRIPTIONS[type]).toBeDefined();
            });
        });

        it('descriptions explain the relation direction correctly', () => {
            // Active relations should start with "This resource..."
            expect(RELATION_TYPE_DESCRIPTIONS['Cites']).toContain('This resource');
            expect(RELATION_TYPE_DESCRIPTIONS['IsCitedBy']).toContain('This resource');
        });
    });
});

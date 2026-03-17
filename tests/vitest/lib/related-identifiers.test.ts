import { describe, expect, it } from 'vitest';

import { getAllRelationTypes, getOppositeRelationType, MOST_USED_RELATION_TYPES, RELATION_TYPES_GROUPED } from '@/lib/related-identifiers';

describe('RELATION_TYPES_GROUPED', () => {
    it('has all expected categories', () => {
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
        expect(categories).toContain('Translation');
        expect(categories).toContain('Other');
    });

    it('each category has at least one relation type', () => {
        for (const [, types] of Object.entries(RELATION_TYPES_GROUPED)) {
            expect(types.length).toBeGreaterThan(0);
        }
    });
});

describe('MOST_USED_RELATION_TYPES', () => {
    it('is non-empty', () => {
        expect(MOST_USED_RELATION_TYPES.length).toBeGreaterThan(0);
    });

    it('contains Cites as most used', () => {
        expect(MOST_USED_RELATION_TYPES).toContain('Cites');
    });
});

describe('getOppositeRelationType', () => {
    it('returns opposite for bidirectional pairs', () => {
        expect(getOppositeRelationType('Cites')).toBe('IsCitedBy');
        expect(getOppositeRelationType('IsCitedBy')).toBe('Cites');
        expect(getOppositeRelationType('HasPart')).toBe('IsPartOf');
        expect(getOppositeRelationType('IsPartOf')).toBe('HasPart');
    });

    it('returns null for unidirectional types', () => {
        expect(getOppositeRelationType('IsPublishedIn')).toBeNull();
        expect(getOppositeRelationType('IsIdenticalTo')).toBeNull();
        expect(getOppositeRelationType('Other')).toBeNull();
    });
});

describe('getAllRelationTypes', () => {
    it('returns flat array of all types', () => {
        const all = getAllRelationTypes();
        expect(all.length).toBeGreaterThan(20);
        expect(all).toContain('Cites');
        expect(all).toContain('Other');
    });

    it('has no duplicates', () => {
        const all = getAllRelationTypes();
        const unique = new Set(all);
        expect(unique.size).toBe(all.length);
    });
});

import { describe, expect, it } from 'vitest';

import { getRelatedWorkDisplayOrder } from '@/lib/related-work-ordering';
import type { RelatedIdentifier } from '@/types';

function work(citation_label?: string | null, overrides: Partial<RelatedIdentifier> = {}): RelatedIdentifier {
    return { identifier: '10.5880/example', identifier_type: 'DOI', relation_type: 'Cites', citation_label, ...overrides };
}

describe('getRelatedWorkDisplayOrder', () => {
    it('handles empty and single-entry lists', () => {
        expect(getRelatedWorkDisplayOrder([])).toEqual([]);
        expect(getRelatedWorkDisplayOrder([work()])).toEqual([0]);
    });

    it('places all forms of empty label first in their original order', () => {
        const items = [work('Zulu'), work(null), work('Alpha'), work(' \t\n '), work(), work('')];
        expect(getRelatedWorkDisplayOrder(items)).toEqual([1, 3, 4, 5, 2, 0]);
    });

    it('uses English case- and accent-insensitive collation with natural numbers and stable ties', () => {
        const items = ['Zulu', 'Work 10', 'álpha', 'Alpha', 'ALPHA', 'Work 2', ' beta '].map((label) => work(label));
        expect(getRelatedWorkDisplayOrder(items)).toEqual([2, 3, 4, 6, 5, 1, 0]);
    });

    it('sorts full labels across relation and identifier types without falling back to identifiers or titles', () => {
        const items = [
            work('Zulu', { identifier: 'a', relation_type: 'Cites' }),
            work('Alpha', { identifier: 'z', identifier_type: 'URL', relation_type: 'References' }),
            work(null, { identifier: 'z', related_title: 'Zulu' }),
            work(null, { identifier: 'a', related_title: 'Alpha' }),
        ];
        expect(getRelatedWorkDisplayOrder(items)).toEqual([2, 3, 1, 0]);
    });

    it('does not mutate the source order, labels, IDs, positions, or provenance', () => {
        const items = Object.freeze([
            Object.freeze(work(' Zulu ', { id: 23, position: 4, source: 'relation_suggestion_assistant' })),
            Object.freeze(work(' Alpha ', { id: 11, position: 7 })),
        ]);
        const before = JSON.stringify(items);
        expect(getRelatedWorkDisplayOrder(items)).toEqual([1, 0]);
        expect(JSON.stringify(items)).toBe(before);
    });
});

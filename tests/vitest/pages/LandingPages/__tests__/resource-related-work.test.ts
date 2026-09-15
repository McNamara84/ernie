import { describe, expect, it } from 'vitest';

import { partitionResourceRelatedWork } from '@/pages/LandingPages/lib/resource-related-work';
import type { LandingPageRelatedIdentifier, LandingPageRelatedItem } from '@/types/landing-page';

function relatedIdentifier(id: number, relationType: string): LandingPageRelatedIdentifier {
    return {
        id,
        identifier: `10.5880/test-${id}`,
        identifier_type: 'DOI',
        relation_type: relationType,
    };
}

function relatedItem(id: number, relationTypeSlug: string | null, relationType: string | null = null): LandingPageRelatedItem {
    return {
        id,
        related_item_type: 'JournalArticle',
        relation_type: relationType,
        relation_type_slug: relationTypeSlug,
        publication_year: 2026,
        volume: null,
        issue: null,
        number: null,
        number_type: null,
        first_page: null,
        last_page: null,
        publisher: null,
        edition: null,
        identifier: `10.5880/item-${id}`,
        identifier_type: 'DOI',
        related_metadata_scheme: null,
        scheme_uri: null,
        scheme_type: null,
        position: id,
        titles: [{ id, title: `Item ${id}`, title_type: 'MainTitle', language: 'en' }],
        creators: [],
        contributors: [],
    };
}

describe('partitionResourceRelatedWork', () => {
    it('partitions both DataCite relation representations without changing source order', () => {
        const identifiers = [
            relatedIdentifier(1, 'References'),
            relatedIdentifier(2, 'IsSupplementTo'),
            relatedIdentifier(3, 'IsDocumentedBy'),
            relatedIdentifier(4, 'IsSupplementTo'),
        ];
        const items = [relatedItem(10, 'IsDocumentedBy'), relatedItem(11, 'References'), relatedItem(12, 'IsSupplementTo')];

        const result = partitionResourceRelatedWork(identifiers, items);

        expect(result.keyPublications.relatedIdentifiers.map(({ id }) => id)).toEqual([2, 4]);
        expect(result.keyPublications.relatedItems.map(({ id }) => id)).toEqual([12]);
        expect(result.datasetDescriptions.relatedIdentifiers.map(({ id }) => id)).toEqual([3]);
        expect(result.datasetDescriptions.relatedItems.map(({ id }) => id)).toEqual([10]);
        expect(result.remaining.relatedIdentifiers.map(({ id }) => id)).toEqual([1]);
        expect(result.remaining.relatedItems.map(({ id }) => id)).toEqual([11]);
        expect(identifiers.map(({ id }) => id)).toEqual([1, 2, 3, 4]);
        expect(items.map(({ id }) => id)).toEqual([10, 11, 12]);
    });

    it('normalizes legacy display names and prefers any recognized relation candidate', () => {
        const result = partitionResourceRelatedWork(
            [relatedIdentifier(1, ' is supplement-to '), relatedIdentifier(2, 'IS_DOCUMENTED_BY')],
            [relatedItem(3, null, 'Is Documented By'), relatedItem(4, 'unknown', 'Is Supplement To')],
        );

        expect(result.keyPublications.relatedIdentifiers.map(({ id }) => id)).toEqual([1]);
        expect(result.keyPublications.relatedItems.map(({ id }) => id)).toEqual([4]);
        expect(result.datasetDescriptions.relatedIdentifiers.map(({ id }) => id)).toEqual([2]);
        expect(result.datasetDescriptions.relatedItems.map(({ id }) => id)).toEqual([3]);
        expect(result.remaining).toEqual({ relatedIdentifiers: [], relatedItems: [] });
    });

    it('returns independent empty groups', () => {
        const result = partitionResourceRelatedWork([], []);

        result.keyPublications.relatedIdentifiers.push(relatedIdentifier(1, 'IsSupplementTo'));

        expect(result.datasetDescriptions.relatedIdentifiers).toEqual([]);
        expect(result.remaining.relatedIdentifiers).toEqual([]);
    });
});

import { describe, expect, it } from 'vitest';

import { relatedItemSchema } from '@/lib/validations/related-item';

function validItem() {
    return {
        related_item_type: 'JournalArticle',
        relation_type_id: 1,
        publication_year: 2023,
        position: 0,
        titles: [{ title: 'Something', title_type: 'MainTitle' as const, position: 0 }],
        creators: [],
        contributors: [],
    };
}

describe('relatedItemSchema', () => {
    it('accepts a minimal valid payload', () => {
        const res = relatedItemSchema.safeParse(validItem());
        expect(res.success).toBe(true);
    });

    it('rejects when no title is provided', () => {
        const res = relatedItemSchema.safeParse({ ...validItem(), titles: [] });
        expect(res.success).toBe(false);
    });

    it('rejects when MainTitle is empty or missing', () => {
        const res = relatedItemSchema.safeParse({
            ...validItem(),
            titles: [{ title: 'Subtitle only', title_type: 'Subtitle' as const, position: 0 }],
        });
        expect(res.success).toBe(false);
    });

    it('rejects an invalid relation_type_id', () => {
        const res = relatedItemSchema.safeParse({ ...validItem(), relation_type_id: 0 });
        expect(res.success).toBe(false);
    });

    it('rejects publication years outside the plausible range', () => {
        const res = relatedItemSchema.safeParse({ ...validItem(), publication_year: 500 });
        expect(res.success).toBe(false);
    });

    it('accepts creators with affiliations', () => {
        const res = relatedItemSchema.safeParse({
            ...validItem(),
            creators: [
                {
                    name: 'Doe, Jane',
                    name_type: 'Personal' as const,
                    given_name: 'Jane',
                    family_name: 'Doe',
                    position: 0,
                    affiliations: [{ name: 'GFZ' }],
                },
            ],
        });
        expect(res.success).toBe(true);
    });

    it('requires contributor_type on contributors', () => {
        const res = relatedItemSchema.safeParse({
            ...validItem(),
            contributors: [
                {
                    name: 'Smith, John',
                    name_type: 'Personal' as const,
                    position: 0,
                    affiliations: [],
                    // contributor_type missing
                },
            ],
        });
        expect(res.success).toBe(false);
    });
});

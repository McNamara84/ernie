import { describe, expect, it } from 'vitest';

import { moveResourceSection } from '@/pages/landing-page-templates';
import {
    normalizeResourceColumnOrders,
    RESOURCE_LEFT_COLUMN_SECTIONS,
    RESOURCE_METADATA_SECTIONS,
    RESOURCE_SECTIONS,
    RIGHT_COLUMN_SECTIONS,
} from '@/pages/LandingPages/lib/section-catalog';
import type { ResourceSection } from '@/types/landing-page';

describe('Resource landing-page template layout', () => {
    it('normalizes legacy and malformed layouts to one complete resource module set', () => {
        const orders = normalizeResourceColumnOrders(
            ['files', 'files', 'unknown' as ResourceSection],
            ['location', 'descriptions', 'keywords', 'keywords'],
        );
        const combined = [...orders.left, ...orders.right];

        expect(combined).toHaveLength(RESOURCE_SECTIONS.length);
        expect(new Set(combined).size).toBe(RESOURCE_SECTIONS.length);
        expect(orders.left).toContain('licenses');
        expect(orders.left.at(-1)).toBe('citation');
        expect(orders.right.slice(0, 1)).toEqual(['location']);
        expect(orders.right).toEqual(expect.arrayContaining(RESOURCE_METADATA_SECTIONS));
    });

    it('preserves modules that are already stored in their non-canonical column', () => {
        const orders = normalizeResourceColumnOrders(
            ['location', ...RESOURCE_LEFT_COLUMN_SECTIONS],
            RIGHT_COLUMN_SECTIONS.filter((section) => section !== 'location'),
        );

        expect(orders.left[0]).toBe('location');
        expect(orders.right).not.toContain('location');
    });

    it('moves standalone modules between columns and into an empty column', () => {
        const moved = moveResourceSection(RESOURCE_LEFT_COLUMN_SECTIONS, RIGHT_COLUMN_SECTIONS, 'files', 'abstract');

        expect(moved.left).not.toContain('files');
        expect(moved.right.indexOf('files')).toBe(moved.right.indexOf('abstract') - 1);

        const allRight = normalizeResourceColumnOrders([], RESOURCE_SECTIONS).right;
        const toEmpty = moveResourceSection([], allRight, 'location', 'resource-left-column');

        expect(toEmpty.left).toEqual(['location']);
        expect(toEmpty.right).not.toContain('location');
    });

    it('keeps metadata modules contiguous after a cross-column move', () => {
        const moved = moveResourceSection(
            ['methods', ...RESOURCE_LEFT_COLUMN_SECTIONS],
            RIGHT_COLUMN_SECTIONS.filter((section) => section !== 'methods'),
            'abstract',
            'files',
        );
        const normalized = normalizeResourceColumnOrders(moved.left, moved.right);
        const metadataIndexes = normalized.left
            .map((section, index) => (RESOURCE_METADATA_SECTIONS.includes(section) ? index : -1))
            .filter((index) => index >= 0);

        expect(normalized.left).toContain('abstract');
        expect(normalized.left).toContain('methods');
        expect(normalized.right).not.toContain('abstract');
        expect(metadataIndexes).toEqual(metadataIndexes.map((_, offset) => metadataIndexes[0] + offset));
    });

    it('reorders modules within one column without changing ownership', () => {
        const moved = moveResourceSection(['files', 'licenses', 'citation'], [], 'citation', 'files');

        expect(moved.left).toEqual(['citation', 'files', 'licenses']);
        expect(moved.right).toEqual([]);
    });
});

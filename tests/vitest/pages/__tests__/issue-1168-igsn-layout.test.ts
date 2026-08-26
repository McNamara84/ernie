import { describe, expect, it } from 'vitest';

import { moveIgsnSection } from '@/pages/landing-page-templates';
import {
    IGSN_LEFT_COLUMN_SECTIONS,
    IGSN_RIGHT_COLUMN_SECTIONS,
    IGSN_SECTIONS,
    normalizeIgsnColumnOrders,
} from '@/pages/LandingPages/lib/section-catalog';
import type { IgsnSection } from '@/types/landing-page';

describe('Issue 1168 IGSN template layout', () => {
    it('normalizes legacy layouts with each module once and the image before location', () => {
        const orders = normalizeIgsnColumnOrders(['general', 'general'], ['abstract', 'location']);

        expect([...orders.left, ...orders.right]).toHaveLength(IGSN_SECTIONS.length);
        expect(new Set([...orders.left, ...orders.right]).size).toBe(IGSN_SECTIONS.length);
        expect(orders.right.indexOf('sample_image')).toBe(orders.right.indexOf('location') - 1);
    });

    it('moves modules between columns and into an empty column without losing order', () => {
        const moved = moveIgsnSection(IGSN_LEFT_COLUMN_SECTIONS as IgsnSection[], IGSN_RIGHT_COLUMN_SECTIONS, 'sample_image', 'general');
        expect(moved.left[0]).toBe('sample_image');
        expect(moved.right).not.toContain('sample_image');

        const allRight = normalizeIgsnColumnOrders([], IGSN_SECTIONS).right;
        const toEmpty = moveIgsnSection([], allRight, 'location', 'igsn-left-column');
        expect(toEmpty.left).toEqual(['location']);
        expect(toEmpty.right).not.toContain('location');
    });

    it('reorders modules within one column', () => {
        const moved = moveIgsnSection(['general', 'citation', 'dates'], [], 'dates', 'general');
        expect(moved.left).toEqual(['dates', 'general', 'citation']);
    });
});

import { describe, expect, it } from 'vitest';

import { moveIgsnSection } from '@/pages/landing-page-templates';
import {
    IGSN_HIDDEN_SECTIONS,
    IGSN_LEFT_COLUMN_SECTIONS,
    IGSN_RIGHT_COLUMN_SECTIONS,
    IGSN_SECTIONS,
    normalizeIgsnColumnOrders,
} from '@/pages/LandingPages/lib/section-catalog';
import type { IgsnSection } from '@/types/landing-page';

describe('Issue 1309 IGSN template layout', () => {
    it('normalizes sparse layouts across visible and hidden zones exactly once', () => {
        const orders = normalizeIgsnColumnOrders(['general', 'general'], ['abstract', 'location']);

        expect([...orders.left, ...orders.right, ...orders.hidden]).toHaveLength(IGSN_SECTIONS.length);
        expect(new Set([...orders.left, ...orders.right, ...orders.hidden]).size).toBe(IGSN_SECTIONS.length);
        expect(orders.right[0]).toBe('version_notice');
        expect(orders.hidden).toContain('sample_image');
    });

    it('preserves an explicitly stored citation position across columns', () => {
        const orders = normalizeIgsnColumnOrders(['general'], ['citation', 'location']);

        expect(orders.left).not.toContain('citation');
        expect(orders.right[1]).toBe('citation');
    });

    it('moves modules between columns and into an empty column without losing order', () => {
        const moved = moveIgsnSection(
            IGSN_LEFT_COLUMN_SECTIONS as IgsnSection[],
            IGSN_RIGHT_COLUMN_SECTIONS,
            IGSN_HIDDEN_SECTIONS,
            'sample_image',
            'general',
        );
        expect(moved.left[0]).toBe('sample_image');
        expect(moved.right).not.toContain('sample_image');
        expect(moved.hidden).not.toContain('sample_image');

        const allRight = normalizeIgsnColumnOrders([], IGSN_SECTIONS, []).right;
        const toEmpty = moveIgsnSection([], allRight, [], 'location', 'igsn-left-column');
        expect(toEmpty.left).toEqual(['location']);
        expect(toEmpty.right).not.toContain('location');
    });

    it('reorders modules within one column', () => {
        const moved = moveIgsnSection(['general', 'citation', 'dates'], ['version_notice'], [], 'dates', 'general');
        expect(moved.left).toEqual(['dates', 'general', 'citation']);
    });

    it('never moves the Version Notice into the hidden zone', () => {
        const moved = moveIgsnSection([], ['version_notice'], IGSN_HIDDEN_SECTIONS, 'version_notice', 'igsn-hidden-column');

        expect(moved.right).toEqual(['version_notice']);
        expect(moved.hidden).not.toContain('version_notice');

        const visibleMove = moveIgsnSection([], ['version_notice'], IGSN_HIDDEN_SECTIONS, 'version_notice', 'igsn-left-column');
        expect(visibleMove.left).toEqual(['version_notice']);
        expect(visibleMove.right).toEqual([]);
    });
});

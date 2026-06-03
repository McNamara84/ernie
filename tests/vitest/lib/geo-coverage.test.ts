import { describe, expect, it } from 'vitest';

import { isGlobalCoverageBounds } from '@/lib/geo-coverage';

describe('isGlobalCoverageBounds', () => {
    it('detects an exact world bounding box', () => {
        expect(isGlobalCoverageBounds({ west: -180, east: 180, south: -90, north: 90 }, 'box')).toBe(true);
    });

    it('allows tiny coordinate drift within tolerance', () => {
        expect(isGlobalCoverageBounds({ west: -179.9999995, east: 179.9999995, south: -89.9999995, north: 89.9999995 }, 'box')).toBe(true);
    });

    it('rejects near-world boxes outside the exact tolerance', () => {
        expect(isGlobalCoverageBounds({ west: -179.99, east: 180, south: -90, north: 90 }, 'box')).toBe(false);
    });

    it('rejects non-box geometry types', () => {
        expect(isGlobalCoverageBounds({ west: -180, east: 180, south: -90, north: 90 }, 'polygon')).toBe(false);
    });

    it('rejects incomplete bounds', () => {
        expect(isGlobalCoverageBounds({ west: -180, east: 180, south: null, north: 90 }, 'box')).toBe(false);
    });
});

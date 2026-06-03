import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    DEFAULT_GLOBAL_COVERAGE_TOLERANCE,
    getGlobalCoverageTolerance,
    isGlobalCoverageBounds,
} from '@/lib/geo-coverage';

describe('isGlobalCoverageBounds', () => {
    afterEach(() => {
        vi.unstubAllEnvs();
    });

    it('detects an exact world bounding box', () => {
        expect(isGlobalCoverageBounds({ west: -180, east: 180, south: -90, north: 90 }, 'box')).toBe(true);
    });

    it('allows tiny coordinate drift within tolerance', () => {
        expect(isGlobalCoverageBounds({ west: -179.9999995, east: 179.9999995, south: -89.9999995, north: 89.9999995 }, 'box')).toBe(true);
    });

    it('rejects near-world boxes outside the exact tolerance', () => {
        expect(isGlobalCoverageBounds({ west: -179.99, east: 180, south: -90, north: 90 }, 'box')).toBe(false);
    });

    it('uses the configured Vite tolerance for near-world boxes', () => {
        vi.stubEnv('VITE_GEO_GLOBAL_COVERAGE_TOLERANCE', '0.02');

        expect(isGlobalCoverageBounds({ west: -179.99, east: 179.99, south: -89.99, north: 89.99 }, 'box')).toBe(true);
    });

    it('falls back to the safe default for empty, invalid, or negative Vite tolerance values', () => {
        vi.stubEnv('VITE_GEO_GLOBAL_COVERAGE_TOLERANCE', '');
        expect(getGlobalCoverageTolerance()).toBe(DEFAULT_GLOBAL_COVERAGE_TOLERANCE);

        vi.stubEnv('VITE_GEO_GLOBAL_COVERAGE_TOLERANCE', 'not-a-number');
        expect(getGlobalCoverageTolerance()).toBe(DEFAULT_GLOBAL_COVERAGE_TOLERANCE);

        vi.stubEnv('VITE_GEO_GLOBAL_COVERAGE_TOLERANCE', '-1');
        expect(getGlobalCoverageTolerance()).toBe(DEFAULT_GLOBAL_COVERAGE_TOLERANCE);
    });

    it('rejects non-box geometry types', () => {
        expect(isGlobalCoverageBounds({ west: -180, east: 180, south: -90, north: 90 }, 'polygon')).toBe(false);
    });

    it('rejects incomplete bounds', () => {
        expect(isGlobalCoverageBounds({ west: -180, east: 180, south: null, north: 90 }, 'box')).toBe(false);
    });
});

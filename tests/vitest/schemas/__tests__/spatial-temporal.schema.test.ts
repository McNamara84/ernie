import { describe, expect, it } from 'vitest';

import {
    coverageTypeSchema,
    polygonPointSchema,
    spatialTemporalCoveragesArraySchema,
    spatialTemporalCoverageSchema,
} from '@/schemas/spatial-temporal.schema';

const baseCoverage = {
    id: '1',
    type: 'point' as const,
    latMin: '52.3906',
    lonMin: '13.0644',
    latMax: '',
    lonMax: '',
    startDate: '',
    endDate: '',
    startTime: '',
    endTime: '',
    timezone: '',
    description: '',
};

describe('Spatial-Temporal Schemas', () => {
    describe('coverageTypeSchema', () => {
        it('accepts valid coverage types', () => {
            expect(coverageTypeSchema.safeParse('point').success).toBe(true);
            expect(coverageTypeSchema.safeParse('box').success).toBe(true);
            expect(coverageTypeSchema.safeParse('polygon').success).toBe(true);
        });

        it('rejects invalid coverage type', () => {
            expect(coverageTypeSchema.safeParse('circle').success).toBe(false);
        });
    });

    describe('polygonPointSchema', () => {
        it('accepts valid point', () => {
            expect(polygonPointSchema.safeParse({ lat: 52.39, lon: 13.06 }).success).toBe(true);
        });

        it('rejects latitude out of range', () => {
            expect(polygonPointSchema.safeParse({ lat: 91, lon: 13 }).success).toBe(false);
        });

        it('rejects longitude out of range', () => {
            expect(polygonPointSchema.safeParse({ lat: 52, lon: 181 }).success).toBe(false);
        });
    });

    describe('spatialTemporalCoverageSchema', () => {
        it('accepts valid point coverage', () => {
            const result = spatialTemporalCoverageSchema.safeParse(baseCoverage);
            expect(result.success).toBe(true);
        });

        it('rejects a partially entered point coverage', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                latMin: '52.0',
                lonMin: '',
            });
            expect(result.success).toBe(false);
            if (!result.success) {
                const paths = result.error.issues.map((i) => i.path.join('.'));
                expect(paths).toContain('lonMin');
            }
        });

        it('requires all coordinates for a partially entered box coverage', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                type: 'box',
                latMin: '52.0',
                lonMin: '',
                latMax: '',
                lonMax: '',
            });
            expect(result.success).toBe(false);
            if (!result.success) {
                const paths = result.error.issues.map((i) => i.path.join('.'));
                expect(paths).toContain('lonMin');
                expect(paths).toContain('latMax');
                expect(paths).toContain('lonMax');
            }
        });

        it('accepts valid box coverage', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                type: 'box',
                latMin: '52.0',
                lonMin: '13.0',
                latMax: '53.0',
                lonMax: '14.0',
            });
            expect(result.success).toBe(true);
        });

        it('requires at least 3 points for polygon coverage', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                type: 'polygon',
                polygonPoints: [
                    { lat: 52, lon: 13 },
                    { lat: 53, lon: 14 },
                ],
            });
            expect(result.success).toBe(false);
        });

        it('accepts valid polygon coverage', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                type: 'polygon',
                polygonPoints: [
                    { lat: 52, lon: 13 },
                    { lat: 53, lon: 14 },
                    { lat: 52, lon: 14 },
                ],
            });
            expect(result.success).toBe(true);
        });

        it('validates end date is after start date', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                startDate: '2025-01-15',
                endDate: '2024-01-15',
            });
            expect(result.success).toBe(false);
        });

        it('accepts equal start and end dates', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                startDate: '2024-01-15',
                endDate: '2024-01-15',
            });
            expect(result.success).toBe(true);
        });

        it('rejects reversed times on the same date', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                startDate: '2024-01-15',
                endDate: '2024-01-15',
                startTime: '15:00',
                endTime: '14:59',
            });
            expect(result.success).toBe(false);
        });

        it('accepts equal start and end times on the same date', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                startDate: '2024-01-15',
                endDate: '2024-01-15',
                startTime: '15:00',
                endTime: '15:00',
            });
            expect(result.success).toBe(true);
        });

        it('accepts equivalent times with and without seconds', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                startDate: '2024-01-15',
                endDate: '2024-01-15',
                startTime: '15:00:00',
                endTime: '15:00',
            });
            expect(result.success).toBe(true);
        });

        it('accepts temporal-only coverage without timezone', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                latMin: '',
                lonMin: '',
                startDate: '2026-08-25',
                timezone: '',
            });
            expect(result.success).toBe(true);
        });

        it('accepts end-only coverage', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                latMin: '',
                lonMin: '',
                endDate: '2026-08-27',
                timezone: '',
            });
            expect(result.success).toBe(true);
        });

        it('accepts valid reduced-precision coverage dates', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                latMin: '',
                lonMin: '',
                startDate: '2025',
                endDate: '2026-08',
                temporalMode: 'interval',
            });
            expect(result.success).toBe(true);
        });

        it('rejects non-ISO coverage dates', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                startDate: '12/31/2025',
                endDate: '01/01/2026',
            });
            expect(result.success).toBe(false);
        });

        it('requires a complete corresponding date for each time', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                startDate: '2026-08',
                startTime: '12:30',
            });
            expect(result.success).toBe(false);
            if (!result.success) {
                expect(result.error.issues.map((issue) => issue.path.join('.'))).toContain('startTime');
            }
        });

        it('rejects timezone-only temporal metadata', () => {
            const result = spatialTemporalCoverageSchema.safeParse({
                ...baseCoverage,
                timezone: 'UTC',
            });
            expect(result.success).toBe(false);
            if (!result.success) {
                expect(result.error.issues.map((issue) => issue.path.join('.'))).toContain('timezone');
            }
        });

        it('preserves valid instant semantics', () => {
            expect(
                spatialTemporalCoverageSchema.safeParse({
                    ...baseCoverage,
                    startDate: '2026-05',
                    temporalMode: 'instant',
                }).success,
            ).toBe(true);
            expect(
                spatialTemporalCoverageSchema.safeParse({
                    ...baseCoverage,
                    startDate: '2026-05',
                    endDate: '2026-06',
                    temporalMode: 'instant',
                }).success,
            ).toBe(false);
        });
    });

    describe('spatialTemporalCoveragesArraySchema', () => {
        it('defaults to empty array', () => {
            const result = spatialTemporalCoveragesArraySchema.safeParse(undefined);
            expect(result.success).toBe(true);
            if (result.success) {
                expect(result.data).toEqual([]);
            }
        });

        it('accepts array of coverages', () => {
            const result = spatialTemporalCoveragesArraySchema.safeParse([baseCoverage]);
            expect(result.success).toBe(true);
        });
    });
});

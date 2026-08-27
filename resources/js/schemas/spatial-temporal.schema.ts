/**
 * Spatial-Temporal Coverage Zod Schemas
 *
 * Validation schemas for spatial and temporal coverage entries in the DataCite form.
 */

import { z } from 'zod';

import { isoDateSchema, latitudeSchema, longitudeSchema, timeSchema } from './common.schema';

// =============================================================================
// Coverage Type
// =============================================================================

export const coverageTypes = ['point', 'box', 'polygon', 'line'] as const;

export const coverageTypeSchema = z.enum(coverageTypes);

export type CoverageType = z.infer<typeof coverageTypeSchema>;

// =============================================================================
// Polygon Point Schema
// =============================================================================

export const polygonPointSchema = z.object({
    lat: z.number().min(-90).max(90),
    lon: z.number().min(-180).max(180),
});

export type PolygonPointFormData = z.infer<typeof polygonPointSchema>;

// =============================================================================
// Spatial-Temporal Coverage Schema
// =============================================================================

export const spatialTemporalCoverageSchema = z
    .object({
        id: z.string(),

        // Coverage Type
        type: coverageTypeSchema,

        // Spatial Information (Point/Box)
        latMin: latitudeSchema,
        lonMin: longitudeSchema,
        latMax: latitudeSchema,
        lonMax: longitudeSchema,

        // Spatial Information (Polygon)
        polygonPoints: z.array(polygonPointSchema).optional(),

        // Temporal Information
        startDate: isoDateSchema,
        endDate: isoDateSchema,
        startTime: timeSchema,
        endTime: timeSchema,
        timezone: z.string().max(100).optional().or(z.literal('')),

        // Description
        description: z.string().optional().or(z.literal('')),
    })
    .superRefine((data, ctx) => {
        const hasTemporalOrDescription = !!(data.startDate || data.endDate || data.startTime || data.endTime || data.timezone || data.description);
        const hasPointCoordinates = !!(data.latMin || data.lonMin);
        const hasBoxCoordinates = !!(data.latMin || data.lonMin || data.latMax || data.lonMax);
        const pointCount = data.polygonPoints?.length ?? 0;

        // Spatial data is optional, but partially entered geometry is invalid.
        if (data.type === 'point' && hasPointCoordinates) {
            if (!data.latMin) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Latitude is required for point coverage',
                    path: ['latMin'],
                });
            }
            if (!data.lonMin) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Longitude is required for point coverage',
                    path: ['lonMin'],
                });
            }
        }

        // Validate box type requires all four coordinates
        if (data.type === 'box' && hasBoxCoordinates) {
            if (!data.latMin) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Minimum latitude is required for box coverage',
                    path: ['latMin'],
                });
            }
            if (!data.lonMin) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Minimum longitude is required for box coverage',
                    path: ['lonMin'],
                });
            }
            if (!data.latMax) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Maximum latitude is required for box coverage',
                    path: ['latMax'],
                });
            }
            if (!data.lonMax) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Maximum longitude is required for box coverage',
                    path: ['lonMax'],
                });
            }
        }

        // Validate polygon type requires at least 3 points
        if (data.type === 'polygon' && pointCount > 0) {
            if (!data.polygonPoints || data.polygonPoints.length < 3) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Polygon coverage requires at least 3 points',
                    path: ['polygonPoints'],
                });
            }
        }

        // Validate line type requires at least 2 points
        if (data.type === 'line' && pointCount > 0) {
            if (!data.polygonPoints || data.polygonPoints.length < 2) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Line coverage requires at least 2 points',
                    path: ['polygonPoints'],
                });
            }
        }

        // Validate date range (endDate >= startDate)
        if (data.startDate && data.endDate && data.startDate > data.endDate) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                message: 'End date must be after or equal to start date',
                path: ['endDate'],
            });
        }

        if (data.startDate && data.startDate === data.endDate && data.startTime && data.endTime && data.startTime > data.endTime) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                message: 'End time must be after or equal to start time when dates are the same',
                path: ['endTime'],
            });
        }

        if (!hasTemporalOrDescription && !hasPointCoordinates && !hasBoxCoordinates && pointCount === 0) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                message: 'Coverage must contain spatial, temporal, or descriptive information',
                path: ['type'],
            });
        }
    });

export type SpatialTemporalCoverageFormData = z.infer<typeof spatialTemporalCoverageSchema>;

// =============================================================================
// Spatial-Temporal Coverages Array Schema
// =============================================================================

export const spatialTemporalCoveragesArraySchema = z.array(spatialTemporalCoverageSchema).default([]);

export type SpatialTemporalCoveragesArrayFormData = z.infer<typeof spatialTemporalCoveragesArraySchema>;

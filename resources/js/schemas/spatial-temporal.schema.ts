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

export const coverageTypes = ['point', 'box', 'polygon'] as const;

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
        timezone: z.string().min(1, 'Timezone is required'),

        // Description
        description: z.string().optional().or(z.literal('')),
    })
    .superRefine((data, ctx) => {
        // Validate point type requires latMin and lonMin
        if (data.type === 'point') {
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
        if (data.type === 'box') {
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
        if (data.type === 'polygon') {
            if (!data.polygonPoints || data.polygonPoints.length < 3) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Polygon coverage requires at least 3 points',
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
    });

export type SpatialTemporalCoverageFormData = z.infer<typeof spatialTemporalCoverageSchema>;

// =============================================================================
// Spatial-Temporal Coverages Array Schema
// =============================================================================

export const spatialTemporalCoveragesArraySchema = z.array(spatialTemporalCoverageSchema).default([]);

export type SpatialTemporalCoveragesArrayFormData = z.infer<typeof spatialTemporalCoveragesArraySchema>;

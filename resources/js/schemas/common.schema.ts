/**
 * Common Zod Schemas
 *
 * Shared validation schemas used across multiple form schemas.
 */

import { z } from 'zod';

// =============================================================================
// Affiliation Schemas
// =============================================================================

/**
 * Affiliation tag schema (used in Tagify components)
 */
export const affiliationTagSchema = z.object({
    value: z.string().min(1, 'Affiliation name is required'),
    rorId: z.string().optional().nullable(),
});

export type AffiliationTagFormData = z.infer<typeof affiliationTagSchema>;

// =============================================================================
// Date Schemas
// =============================================================================

/**
 * ISO date string (YYYY-MM-DD)
 */
export const isoDateSchema = z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, 'Date must be in YYYY-MM-DD format')
    .optional()
    .or(z.literal(''))
    .nullable();

/**
 * Time string (HH:MM)
 */
export const timeSchema = z
    .string()
    .regex(/^\d{2}:\d{2}$/, 'Time must be in HH:MM format')
    .optional()
    .or(z.literal(''));

// =============================================================================
// Identifier Schemas
// =============================================================================

/**
 * DOI format validation
 */
export const doiSchema = z
    .string()
    .regex(/^10\.\d{4,}\//, 'DOI must start with 10.XXXX/')
    .optional()
    .or(z.literal(''));

/**
 * ORCID format: 0000-0000-0000-000X (16 digits with hyphens, last can be X)
 */
export const orcidSchema = z
    .string()
    .regex(/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/, 'Invalid ORCID format (e.g., 0000-0001-2345-6789)')
    .optional()
    .or(z.literal(''));

/**
 * ROR ID format validation
 */
export const rorIdSchema = z
    .string()
    .regex(/^https:\/\/ror\.org\/[a-z0-9]+$/, 'Invalid ROR ID format')
    .optional()
    .or(z.literal(''))
    .nullable();

/**
 * URL validation (optional)
 */
export const optionalUrlSchema = z.string().url('Invalid URL').optional().or(z.literal(''));

// =============================================================================
// Year Schema
// =============================================================================

const currentYear = new Date().getFullYear();

export const yearSchema = z
    .string()
    .regex(/^\d{4}$/, 'Year must be a 4-digit number')
    .refine((val) => {
        const year = parseInt(val, 10);
        return year >= 1900 && year <= currentYear + 1;
    }, `Year must be between 1900 and ${currentYear + 1}`)
    .optional()
    .or(z.literal(''));

// =============================================================================
// Version Schema
// =============================================================================

export const versionSchema = z
    .string()
    .regex(/^\d+(\.\d+)*$/, 'Version must follow semantic versioning (e.g., 1.0.0)')
    .optional()
    .or(z.literal(''));

// =============================================================================
// Coordinate Schemas
// =============================================================================

export const latitudeSchema = z
    .string()
    .refine(
        (val) => {
            if (!val) return true;
            const num = parseFloat(val);
            return !isNaN(num) && num >= -90 && num <= 90;
        },
        { message: 'Latitude must be between -90 and +90' }
    )
    .optional()
    .or(z.literal(''));

export const longitudeSchema = z
    .string()
    .refine(
        (val) => {
            if (!val) return true;
            const num = parseFloat(val);
            return !isNaN(num) && num >= -180 && num <= 180;
        },
        { message: 'Longitude must be between -180 and +180' }
    )
    .optional()
    .or(z.literal(''));

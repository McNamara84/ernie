/**
 * Resource Zod Schema
 *
 * Main validation schema for the DataCite metadata form.
 * This is the primary schema used for resource creation and editing.
 */

import { z } from 'zod';

import { authorsArraySchema, authorsWithContactSchema } from './author.schema';
import { doiSchema, versionSchema, yearSchema } from './common.schema';
import { contributorsArraySchema } from './contributor.schema';
import { fundingReferencesArraySchema } from './funding-reference.schema';
import { relatedIdentifiersArraySchema } from './related-work.schema';
import { spatialTemporalCoveragesArraySchema } from './spatial-temporal.schema';

// =============================================================================
// Title Schema
// =============================================================================

export const titleSchema = z.object({
    id: z.string(),
    title: z.string().min(1, 'Title is required'),
    titleType: z.string(),
});

export type TitleFormData = z.infer<typeof titleSchema>;

export const titlesArraySchema = z.array(titleSchema).min(1, 'At least one title is required');

// =============================================================================
// License Schema
// =============================================================================

export const licenseSchema = z.object({
    id: z.string(),
    license: z.string().min(1, 'License is required'),
});

export type LicenseFormData = z.infer<typeof licenseSchema>;

export const licensesArraySchema = z.array(licenseSchema).min(1, 'At least one license is required');

// =============================================================================
// Date Schema
// =============================================================================

export const dateEntrySchema = z.object({
    id: z.string(),
    startDate: z.string().nullable(),
    endDate: z.string().nullable(),
    dateType: z.string().min(1, 'Date type is required'),
});

export type DateEntryFormData = z.infer<typeof dateEntrySchema>;

export const datesArraySchema = z.array(dateEntrySchema).default([]);

// =============================================================================
// Description Schema
// =============================================================================

export const descriptionSchema = z.object({
    id: z.string(),
    type: z.string().min(1, 'Description type is required'),
    description: z.string().min(1, 'Description is required'),
});

export type DescriptionFormData = z.infer<typeof descriptionSchema>;

export const descriptionsArraySchema = z.array(descriptionSchema).default([]);

// =============================================================================
// GCMD Keyword Schema
// =============================================================================

export const gcmdKeywordSchema = z.object({
    id: z.string(),
    path: z.string(),
    text: z.string(),
    scheme: z.string(),
    schemeURI: z.string().optional(),
    language: z.string().optional(),
    isLegacy: z.string().optional(),
});

export type GcmdKeywordFormData = z.infer<typeof gcmdKeywordSchema>;

export const gcmdKeywordsArraySchema = z.array(gcmdKeywordSchema).default([]);

// =============================================================================
// Free Keyword Schema
// =============================================================================

export const freeKeywordsArraySchema = z.array(z.string()).default([]);

// =============================================================================
// MSL Laboratory Schema
// =============================================================================

export const mslLaboratorySchema = z.object({
    identifier: z.string(),
    name: z.string(),
    affiliation_name: z.string(),
    affiliation_ror: z.string(),
});

export type MslLaboratoryFormData = z.infer<typeof mslLaboratorySchema>;

export const mslLaboratoriesArraySchema = z.array(mslLaboratorySchema).default([]);

// =============================================================================
// Main Resource Schema
// =============================================================================

export const resourceSchema = z.object({
    // Basic Information
    doi: doiSchema,
    year: yearSchema,
    resourceType: z.string().min(1, 'Resource type is required'),
    version: versionSchema,
    language: z.string().min(1, 'Language is required'),

    // Titles (at least one required)
    titles: titlesArraySchema,

    // Authors (at least one required)
    authors: authorsArraySchema,

    // Contributors (optional)
    contributors: contributorsArraySchema,

    // Licenses (at least one required)
    licenses: licensesArraySchema,

    // Descriptions (optional)
    descriptions: descriptionsArraySchema,

    // Dates (optional)
    dates: datesArraySchema,

    // Keywords
    gcmdKeywords: gcmdKeywordsArraySchema,
    freeKeywords: freeKeywordsArraySchema,

    // Spatial-Temporal Coverage (optional)
    spatialTemporalCoverages: spatialTemporalCoveragesArraySchema,

    // Related Works (optional)
    relatedWorks: relatedIdentifiersArraySchema,

    // Funding References (optional)
    fundingReferences: fundingReferencesArraySchema,

    // MSL Laboratories (optional)
    mslLaboratories: mslLaboratoriesArraySchema,

    // Resource ID (for updates)
    resourceId: z.string().optional(),
});

export type ResourceFormData = z.infer<typeof resourceSchema>;

// =============================================================================
// Resource Schema with Contact Validation
// =============================================================================

export const resourceWithContactSchema = resourceSchema.extend({
    authors: authorsWithContactSchema,
});

export type ResourceWithContactFormData = z.infer<typeof resourceWithContactSchema>;

// =============================================================================
// Partial Resource Schema (for drafts/partial saves)
// =============================================================================

export const partialResourceSchema = resourceSchema.partial();

export type PartialResourceFormData = z.infer<typeof partialResourceSchema>;

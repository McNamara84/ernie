/**
 * Funding Reference Zod Schemas
 *
 * Validation schemas for funding reference entries in the DataCite form.
 */

import { z } from 'zod';

// =============================================================================
// Funder Identifier Types (DataCite 4.6)
// =============================================================================

export const funderIdentifierTypes = ['ROR', 'Crossref Funder ID', 'ISNI', 'GRID', 'Other'] as const;

export const funderIdentifierTypeSchema = z.enum(funderIdentifierTypes).nullable();

export type FunderIdentifierType = z.infer<typeof funderIdentifierTypeSchema>;

// =============================================================================
// Funding Reference Schema
// =============================================================================

export const fundingReferenceSchema = z.object({
    id: z.string(),
    funderName: z.string().min(1, 'Funder name is required'),
    funderIdentifier: z.string().optional().or(z.literal('')),
    funderIdentifierType: funderIdentifierTypeSchema,
    awardNumber: z.string().optional().or(z.literal('')),
    awardUri: z.string().url('Invalid Award URI').optional().or(z.literal('')),
    awardTitle: z.string().optional().or(z.literal('')),
    // UI state for progressive disclosure
    isExpanded: z.boolean().default(false),
});

export type FundingReferenceFormData = z.infer<typeof fundingReferenceSchema>;

// =============================================================================
// Funding References Array Schema
// =============================================================================

export const fundingReferencesArraySchema = z.array(fundingReferenceSchema).default([]);

export type FundingReferencesArrayFormData = z.infer<typeof fundingReferencesArraySchema>;

// =============================================================================
// ROR Funder Schema (for autocomplete results)
// =============================================================================

export const rorFunderSchema = z.object({
    prefLabel: z.string(),
    rorId: z.string(),
    otherLabel: z.array(z.string()).default([]),
});

export type RorFunderFormData = z.infer<typeof rorFunderSchema>;

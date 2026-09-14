/**
 * Author Zod Schemas
 *
 * Validation schemas for author entries in the DataCite form.
 * Supports both person and institution author types.
 */

import { z } from 'zod';

import { affiliationTagSchema, orcidSchema } from './common.schema';

export const RESOURCE_CREATOR_NAME_SNAPSHOT_MAX_LENGTH = 1000;

// =============================================================================
// Person Author Schema
// =============================================================================

export const personAuthorSchema = z
    .object({
        id: z.string(),
        type: z.literal('person'),
        resourceCreatorId: z.number().int().positive().optional(),
        orcid: orcidSchema,
        firstName: z.string(),
        lastName: z.string(),
        nameSnapshot: z.string().max(RESOURCE_CREATOR_NAME_SNAPSHOT_MAX_LENGTH).optional(),
        email: z.string().email('Invalid email address').optional().or(z.literal('')),
        website: z.string().url('Invalid URL').optional().or(z.literal('')),
        isContact: z.boolean().default(false),
        affiliations: z.array(affiliationTagSchema).default([]),
        affiliationsInput: z.string().default(''),
        // ORCID verification status (optional, set by system)
        orcidVerified: z.boolean().optional(),
        orcidVerifiedAt: z.string().optional(),
    })
    .refine((author) => author.lastName.trim() !== '' || (author.nameSnapshot?.trim() ?? '') !== '', {
        message: 'Last name is required',
        path: ['lastName'],
    });

export type PersonAuthorFormData = z.infer<typeof personAuthorSchema>;

// =============================================================================
// Institution Author Schema
// =============================================================================

export const institutionAuthorSchema = z.object({
    id: z.string(),
    type: z.literal('institution'),
    institutionName: z.string().min(1, 'Institution name is required'),
    affiliations: z.array(affiliationTagSchema).default([]),
    affiliationsInput: z.string().default(''),
});

export type InstitutionAuthorFormData = z.infer<typeof institutionAuthorSchema>;

// =============================================================================
// Combined Author Schema
// =============================================================================

export const authorSchema = z.discriminatedUnion('type', [personAuthorSchema, institutionAuthorSchema]);

export type AuthorFormData = z.infer<typeof authorSchema>;

// =============================================================================
// Author Array Schema (with at least one author required)
// =============================================================================

export const authorsArraySchema = z.array(authorSchema).min(1, 'At least one author is required');

export type AuthorsArrayFormData = z.infer<typeof authorsArraySchema>;

// =============================================================================
// Validation Helpers
// =============================================================================

/**
 * Validate that at least one author is marked as contact
 */
export const validateContactAuthor = (authors: AuthorFormData[]): boolean => {
    return authors.some((author) => author.type === 'person' && author.isContact);
};

/**
 * Refine authors array to ensure at least one contact author
 */
export const authorsWithContactSchema = authorsArraySchema.refine((authors) => authors.some((a) => a.type === 'person' && a.isContact), {
    message: 'At least one person author must be marked as contact',
});

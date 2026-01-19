/**
 * Contributor Zod Schemas
 *
 * Validation schemas for contributor entries in the DataCite form.
 * Supports both person and institution contributor types.
 */

import { z } from 'zod';

import { affiliationTagSchema, orcidSchema } from './common.schema';

// =============================================================================
// Role Tag Schema
// =============================================================================

export const contributorRoleTagSchema = z.object({
    value: z.string().min(1, 'Role is required'),
});

export type ContributorRoleTagFormData = z.infer<typeof contributorRoleTagSchema>;

// =============================================================================
// Person Contributor Schema
// =============================================================================

export const personContributorSchema = z.object({
    id: z.string(),
    type: z.literal('person'),
    orcid: orcidSchema,
    firstName: z.string().min(1, 'First name is required'),
    lastName: z.string().min(1, 'Last name is required'),
    roles: z.array(contributorRoleTagSchema).min(1, 'At least one role is required'),
    rolesInput: z.string().default(''),
    affiliations: z.array(affiliationTagSchema).default([]),
    affiliationsInput: z.string().default(''),
    // ORCID verification status (optional, set by system)
    orcidVerified: z.boolean().optional(),
    orcidVerifiedAt: z.string().optional(),
});

export type PersonContributorFormData = z.infer<typeof personContributorSchema>;

// =============================================================================
// Institution Contributor Schema
// =============================================================================

export const institutionContributorSchema = z.object({
    id: z.string(),
    type: z.literal('institution'),
    institutionName: z.string().min(1, 'Institution name is required'),
    roles: z.array(contributorRoleTagSchema).min(1, 'At least one role is required'),
    rolesInput: z.string().default(''),
    affiliations: z.array(affiliationTagSchema).default([]),
    affiliationsInput: z.string().default(''),
});

export type InstitutionContributorFormData = z.infer<typeof institutionContributorSchema>;

// =============================================================================
// Combined Contributor Schema
// =============================================================================

export const contributorSchema = z.discriminatedUnion('type', [personContributorSchema, institutionContributorSchema]);

export type ContributorFormData = z.infer<typeof contributorSchema>;

// =============================================================================
// Contributor Array Schema (contributors are optional)
// =============================================================================

export const contributorsArraySchema = z.array(contributorSchema).default([]);

export type ContributorsArrayFormData = z.infer<typeof contributorsArraySchema>;

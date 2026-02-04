/**
 * Zod Schemas Index
 *
 * Central export for all validation schemas used in the ERNIE application.
 * Import schemas from this file for consistent validation across the app.
 *
 * @example
 * import { resourceSchema, authorSchema, userSchemas } from '@/schemas';
 *
 * // Validate form data
 * const result = resourceSchema.safeParse(formData);
 * if (!result.success) {
 *   console.error(result.error.flatten());
 * }
 */

// =============================================================================
// Common Schemas
// =============================================================================

export {
    type AffiliationTagFormData,
    affiliationTagSchema,
    doiSchema,
    isoDateSchema,
    latitudeSchema,
    longitudeSchema,
    optionalUrlSchema,
    orcidSchema,
    rorIdSchema,
    timeSchema,
    versionSchema,
    yearSchema,
} from './common.schema';

// =============================================================================
// Author Schemas
// =============================================================================

export {
    type AuthorFormData,
    type AuthorsArrayFormData,
    authorsArraySchema,
    authorSchema,
    authorsWithContactSchema,
    type InstitutionAuthorFormData,
    institutionAuthorSchema,
    type PersonAuthorFormData,
    personAuthorSchema,
    validateContactAuthor,
} from './author.schema';

// =============================================================================
// Contributor Schemas
// =============================================================================

export {
    type ContributorFormData,
    type ContributorRoleTagFormData,
    contributorRoleTagSchema,
    type ContributorsArrayFormData,
    contributorsArraySchema,
    contributorSchema,
    type InstitutionContributorFormData,
    institutionContributorSchema,
    type PersonContributorFormData,
    personContributorSchema,
} from './contributor.schema';

// =============================================================================
// Funding Reference Schemas
// =============================================================================

export {
    type FunderIdentifierType,
    funderIdentifierTypes,
    funderIdentifierTypeSchema,
    type FundingReferenceFormData,
    type FundingReferencesArrayFormData,
    fundingReferencesArraySchema,
    fundingReferenceSchema,
    type RorFunderFormData,
    rorFunderSchema,
} from './funding-reference.schema';

// =============================================================================
// Related Work Schemas
// =============================================================================

export {
    type IdentifierType,
    identifierTypes,
    identifierTypeSchema,
    type RelatedIdentifierFormData,
    type RelatedIdentifiersArrayFormData,
    relatedIdentifiersArraySchema,
    relatedIdentifierSchema,
    type RelatedWorkFormData,
    relatedWorkFormSchema,
    type RelationType,
    relationTypes,
    relationTypeSchema,
} from './related-work.schema';

// =============================================================================
// Spatial-Temporal Coverage Schemas
// =============================================================================

export {
    type CoverageType,
    coverageTypes,
    coverageTypeSchema,
    type PolygonPointFormData,
    polygonPointSchema,
    type SpatialTemporalCoverageFormData,
    type SpatialTemporalCoveragesArrayFormData,
    spatialTemporalCoveragesArraySchema,
    spatialTemporalCoverageSchema,
} from './spatial-temporal.schema';

// =============================================================================
// Resource Schemas
// =============================================================================

export {
    type DateEntryFormData,
    dateEntrySchema,
    datesArraySchema,
    type DescriptionFormData,
    descriptionsArraySchema,
    descriptionSchema,
    freeKeywordsArraySchema,
    type GcmdKeywordFormData,
    gcmdKeywordsArraySchema,
    gcmdKeywordSchema,
    type LicenseFormData,
    licensesArraySchema,
    licenseSchema,
    mslLaboratoriesArraySchema,
    type MslLaboratoryFormData,
    mslLaboratorySchema,
    type PartialResourceFormData,
    partialResourceSchema,
    type ResourceFormData,
    resourceSchema,
    type ResourceWithContactFormData,
    resourceWithContactSchema,
    type TitleFormData,
    titlesArraySchema,
    titleSchema,
} from './resource.schema';

// =============================================================================
// User Schemas
// =============================================================================

export {
    type AddUserFormData,
    addUserSchema,
    type EditUserFormData,
    editUserSchema,
    type ForgotPasswordFormData,
    forgotPasswordSchema,
    type LoginFormData,
    loginSchema,
    type RegistrationFormData,
    registrationSchema,
    type ResetPasswordFormData,
    resetPasswordSchema,
    type UserRole,
    userRoles,
    userRoleSchema,
    // Password change and profile update schemas are now in @/lib/validations/user.ts
    // Use: updatePasswordSchema, updateProfileSchema, deleteAccountSchema
} from './user.schema';

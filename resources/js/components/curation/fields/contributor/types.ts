/**
 * Type definitions for Contributor components
 *
 * This file contains all TypeScript types and interfaces for the Contributor field group.
 * Migrated from contributor-field.tsx for better organization.
 */

import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

export type ContributorType = 'person' | 'institution';

/**
 * Role tag for contributors
 */
export interface ContributorRoleTag {
    value: string;
    [key: string]: unknown;
}

/**
 * Base interface for all contributor entries
 */
interface BaseContributorEntry {
    id: string;
    type: ContributorType;
    roles: ContributorRoleTag[];
    rolesInput: string;
    affiliations: AffiliationTag[];
    affiliationsInput: string;
}

/**
 * Person contributor entry with ORCID
 */
export interface PersonContributorEntry extends BaseContributorEntry {
    type: 'person';
    orcid: string;
    firstName: string;
    lastName: string;
    // ORCID verification status
    orcidVerified?: boolean;
    orcidVerifiedAt?: string;
}

/**
 * Institution contributor entry
 */
export interface InstitutionContributorEntry extends BaseContributorEntry {
    type: 'institution';
    institutionName: string;
}

/**
 * Union type for all contributor entries
 */
export type ContributorEntry = PersonContributorEntry | InstitutionContributorEntry;

/**
 * Props for the main ContributorField component
 */
export interface ContributorFieldProps {
    contributors: ContributorEntry[];
    onChange: (contributors: ContributorEntry[]) => void;
    affiliationSuggestions: AffiliationSuggestion[];
    personRoleOptions: readonly string[];
    institutionRoleOptions: readonly string[];
}

/**
 * Maximum number of contributors allowed
 */
export const MAX_CONTRIBUTORS = 100;

/**
 * Type definitions for Author components
 *
 * This file contains all TypeScript types and interfaces for the Author field group.
 * Migrated from author-field.tsx for better organization.
 */

import type { Role } from '@/types';
import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

export type AuthorType = 'person' | 'institution';

/**
 * Base interface for all author entries
 */
interface BaseAuthorEntry {
    id: string;
    affiliations: AffiliationTag[];
    affiliationsInput: string;
}

/**
 * Person author entry with ORCID and contact information
 */
export interface PersonAuthorEntry extends BaseAuthorEntry {
    type: 'person';
    orcid: string;
    firstName: string;
    lastName: string;
    email: string;
    website: string;
    isContact: boolean;
    // ORCID verification status
    orcidVerified?: boolean;
    orcidVerifiedAt?: string;
}

/**
 * Institution author entry
 */
export interface InstitutionAuthorEntry extends BaseAuthorEntry {
    type: 'institution';
    institutionName: string;
}

/**
 * Union type for all author entries
 */
export type AuthorEntry = PersonAuthorEntry | InstitutionAuthorEntry;

/**
 * Props for the main AuthorField component
 */
export interface AuthorFieldProps {
    authors: AuthorEntry[];
    onChange: (authors: AuthorEntry[]) => void;
    affiliationSuggestions: AffiliationSuggestion[];
    authorRoles?: Role[];
}

/**
 * Maximum number of authors allowed
 */
export const MAX_AUTHORS = 100;

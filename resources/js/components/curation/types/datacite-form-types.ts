/**
 * DataCite Form Types
 *
 * Shared type definitions for the DataCite metadata editor form.
 * Extracted from datacite-form.tsx for better maintainability.
 */

import type { DateType, Language, License, MSLLaboratory, RelatedIdentifier, ResourceType, Role, TitleType } from '@/types';

import type { FundingReferenceEntry } from '../fields/funding-reference';
import type { SpatialTemporalCoverageEntry } from '../fields/spatial-temporal-coverage/types';

// Re-export types that are used by consumers of this module
export type { AuthorEntry } from '../fields/author';
export type { ContributorEntry, ContributorRoleTag } from '../fields/contributor';
export type { DescriptionEntry } from '../fields/description-field';
export type { FundingReferenceEntry } from '../fields/funding-reference';
export type { SpatialTemporalCoverageEntry } from '../fields/spatial-temporal-coverage/types';
export type { AffiliationTag } from '@/types/affiliations';

// ============================================================================
// Form Data Types
// ============================================================================

export interface DataCiteFormData {
    doi: string;
    year: string;
    resourceType: string;
    version: string;
    language: string;
}

export interface TitleEntry {
    id: string;
    title: string;
    titleType: string;
}

export interface LicenseEntry {
    id: string;
    license: string;
}

export interface DateEntry {
    id: string;
    startDate: string | null;
    endDate: string | null;
    dateType: string;
}

// ============================================================================
// Serialization Types (for API payloads)
// ============================================================================

export interface SerializedAffiliation {
    value: string;
    rorId: string | null;
}

export type SerializedAuthor =
    | {
          type: 'person';
          orcid: string | null;
          firstName: string | null;
          lastName: string;
          email: string | null;
          website: string | null;
          isContact: boolean;
          affiliations: SerializedAffiliation[];
          position: number;
      }
    | {
          type: 'institution';
          institutionName: string;
          rorId: string | null;
          affiliations: SerializedAffiliation[];
          position: number;
      };

export type SerializedContributor =
    | {
          type: 'person';
          orcid: string | null;
          firstName: string | null;
          lastName: string;
          roles: string[];
          affiliations: SerializedAffiliation[];
          position: number;
      }
    | {
          type: 'institution';
          institutionName: string;
          roles: string[];
          affiliations: SerializedAffiliation[];
          position: number;
      };

// ============================================================================
// Initial Data Types (from backend/XML)
// ============================================================================

export type InitialAffiliationInput = {
    value?: string | null;
    rorId?: string | null;
};

type BaseInitialAuthor = {
    affiliations?: (InitialAffiliationInput | null | undefined)[] | null;
};

export type InitialAuthor =
    | (BaseInitialAuthor & {
          type?: 'person';
          orcid?: string | null;
          firstName?: string | null;
          lastName?: string | null;
          email?: string | null;
          website?: string | null;
          isContact?: boolean | string | null;
      })
    | (BaseInitialAuthor & {
          type: 'institution';
          institutionName?: string | null;
          rorId?: string | null;
      });

type BaseInitialContributor = {
    affiliations?: (InitialAffiliationInput | null | undefined)[] | null;
    roles?: (string | null | undefined)[] | Record<string, unknown> | string | null;
};

export type InitialContributor =
    | (BaseInitialContributor & {
          type?: 'person';
          orcid?: string | null;
          firstName?: string | null;
          lastName?: string | null;
      })
    | (BaseInitialContributor & {
          type: 'institution';
          institutionName?: string | null;
      });

// ============================================================================
// Component Props
// ============================================================================

export interface DataCiteFormProps {
    resourceTypes: ResourceType[];
    titleTypes: TitleType[];
    dateTypes: DateType[];
    licenses: License[];
    languages: Language[];
    contributorPersonRoles?: Role[];
    contributorInstitutionRoles?: Role[];
    authorRoles?: Role[];
    maxTitles?: number;
    maxLicenses?: number;
    googleMapsApiKey: string;
    initialDoi?: string;
    initialYear?: string;
    initialVersion?: string;
    initialLanguage?: string;
    initialResourceType?: string;
    initialTitles?: { title: string; titleType: string }[];
    initialLicenses?: string[];
    initialResourceId?: string;
    initialAuthors?: InitialAuthor[];
    initialContributors?: InitialContributor[];
    initialDescriptions?: { type: string; description: string }[];
    initialDates?: { dateType: string; startDate: string; endDate: string }[];
    initialGcmdKeywords?: {
        id: string;
        path: string;
        text: string;
        scheme: string;
        schemeURI?: string;
        language?: string;
        isLegacy?: string;
    }[];
    initialFreeKeywords?: string[];
    initialSpatialTemporalCoverages?: SpatialTemporalCoverageEntry[];
    initialRelatedWorks?: RelatedIdentifier[];
    initialFundingReferences?: FundingReferenceEntry[];
    initialMslLaboratories?: MSLLaboratory[];
    /** Optional: Whether the current user is an admin (used for DOI editing permissions) */
    isUserAdmin?: boolean;
}

// ============================================================================
// Constants
// ============================================================================

export const MAIN_TITLE_SLUG = 'main-title';

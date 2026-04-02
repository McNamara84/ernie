/**
 * DataCite Form Types
 *
 * Shared type definitions for the DataCite metadata editor form.
 * Extracted from datacite-form.tsx for better maintainability.
 */

import type { DateType, DescriptionType, InstrumentSelection, Language, License, MSLLaboratory, RelatedIdentifier, ResourceType, Role, TitleType } from '@/types';

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
    startTime: string | null;
    endTime: string | null;
    startTimezone: string | null;
    endTimezone: string | null;
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
          email: string | null;
          website: string | null;
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
          orcidVerified?: boolean;
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
          email?: string | null;
          website?: string | null;
          orcidVerified?: boolean;
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
    descriptionTypes: DescriptionType[];
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
        classificationCode?: string;
        isLegacy?: string;
    }[];
    initialFreeKeywords?: string[];
    initialGemetKeywords?: {
        id: string;
        path: string;
        text: string;
        scheme: string;
        schemeURI?: string;
        language?: string;
        classificationCode?: string;
    }[];
    initialSpatialTemporalCoverages?: SpatialTemporalCoverageEntry[];
    initialRelatedWorks?: RelatedIdentifier[];
    initialFundingReferences?: FundingReferenceEntry[];
    initialMslLaboratories?: MSLLaboratory[];
    initialInstruments?: InstrumentSelection[];
    /** Initial datacenter IDs assigned to this resource */
    initialDatacenters?: number[];
    /** Available datacenters for selection */
    availableDatacenters?: { id: number; name: string }[];
    /** Optional: Whether the current user is an admin (used for DOI editing permissions) */
    isUserAdmin?: boolean;
    /** Active relation type slugs from the backend (only these are shown in the editor) */
    activeRelationTypes?: string[];
    /** Active identifier type slugs from the backend (only these are shown in the editor) */
    activeIdentifierTypes?: string[];
}

// ============================================================================
// Constants
// ============================================================================

export const MAIN_TITLE_SLUG = 'main-title';

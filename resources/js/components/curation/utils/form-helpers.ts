/**
 * DataCite Form Normalization Utilities
 *
 * Helper functions for normalizing and transforming data in the DataCite form.
 * Extracted from datacite-form.tsx for better maintainability.
 */

import { inferContributorTypeFromRoles, normaliseContributorRoleLabel } from '@/lib/contributors';
import type { AffiliationTag } from '@/types/affiliations';

import type { AuthorEntry, InstitutionAuthorEntry, PersonAuthorEntry } from '../fields/author';
import type { ContributorEntry, ContributorRoleTag, InstitutionContributorEntry, PersonContributorEntry } from '../fields/contributor';
import type { InitialAffiliationInput, InitialAuthor, InitialContributor, SerializedAffiliation } from '../types/datacite-form-types';

// ============================================================================
// String Normalization
// ============================================================================

/**
 * Normalize ORCID identifier by removing URL prefix if present.
 * Handles various formats: full URL, www prefix, or bare identifier.
 */
export const normalizeOrcid = (orcid: string | null | undefined): string => {
    if (!orcid || typeof orcid !== 'string') {
        return '';
    }

    const trimmed = orcid.trim();

    // Remove https://orcid.org/ prefix if present
    const orcidPattern = /^(?:https?:\/\/)?(?:www\.)?orcid\.org\/(.+)$/i;
    const match = trimmed.match(orcidPattern);

    if (match && match[1]) {
        return match[1];
    }

    return trimmed;
};

/**
 * Normalize website URL by ensuring it has a protocol prefix.
 */
export const normalizeWebsiteUrl = (url: string | null | undefined): string => {
    if (!url || typeof url !== 'string') {
        return '';
    }

    const trimmed = url.trim();

    // If URL doesn't start with http:// or https://, add https://
    if (trimmed && !/^https?:\/\//i.test(trimmed)) {
        return `https://${trimmed}`;
    }

    return trimmed;
};

/**
 * Normalize title type slug to kebab-case format.
 * Handles legacy values that may be in TitleCase or other formats.
 */
export const normalizeTitleTypeSlug = (value: string | null | undefined): string => {
    if (value == null) {
        return '';
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return '';
    }

    return trimmed
        .replace(/_/g, '-')
        .replace(/\s+/g, '-')
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .replace(/[^a-zA-Z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();
};

// ============================================================================
// Affiliation Handling
// ============================================================================

/**
 * Serialize and normalize affiliations from an author or contributor entry.
 * Deduplicates and filters out empty affiliations.
 *
 * Important: The ROR URL is never used as a fallback for the affiliation name.
 * If only a ROR ID is provided without a name, the backend will resolve the
 * organization name from the local ROR data dump.
 */
export const serializeAffiliations = (entry: AuthorEntry | ContributorEntry): SerializedAffiliation[] => {
    const seen = new Set<string>();

    return entry.affiliations
        .map((affiliation) => {
            const rawValue = affiliation.value.trim();
            const rawRorId = typeof affiliation.rorId === 'string' ? affiliation.rorId.trim() : '';

            if (!rawValue && !rawRorId) {
                return null;
            }

            const value = rawValue;
            const rorId = rawRorId || null;
            const key = `${value}|${rorId ?? ''}`;

            if (seen.has(key)) {
                return null;
            }

            seen.add(key);

            return { value, rorId } satisfies SerializedAffiliation;
        })
        .filter((item): item is SerializedAffiliation => item !== null);
};

/**
 * Normalize initial affiliations from backend/XML data to AffiliationTag format.
 */
export const normaliseInitialAffiliations = (affiliations?: (InitialAffiliationInput | null | undefined)[] | null): AffiliationTag[] => {
    if (!affiliations || !Array.isArray(affiliations)) {
        return [];
    }

    return affiliations
        .map((affiliation) => {
            if (!affiliation || typeof affiliation !== 'object') {
                return null;
            }

            // Try multiple property names for value
            const rawValue = (
                'value' in affiliation && typeof affiliation.value === 'string'
                    ? affiliation.value
                    : 'name' in affiliation && typeof (affiliation as Record<string, unknown>).name === 'string'
                      ? ((affiliation as Record<string, unknown>).name as string)
                      : ''
            ).trim();

            // Try multiple property names for rorId
            const rawRorId = (
                'rorId' in affiliation && typeof affiliation.rorId === 'string'
                    ? affiliation.rorId
                    : 'rorid' in affiliation && typeof (affiliation as Record<string, unknown>).rorid === 'string'
                      ? ((affiliation as Record<string, unknown>).rorid as string)
                      : 'identifier' in affiliation && typeof (affiliation as Record<string, unknown>).identifier === 'string'
                        ? ((affiliation as Record<string, unknown>).identifier as string)
                        : ''
            ).trim();

            if (!rawValue && !rawRorId) {
                return null;
            }

            return {
                value: rawValue || rawRorId,
                rorId: rawRorId || null,
            } satisfies AffiliationTag;
        })
        .filter((item): item is AffiliationTag => Boolean(item && item.value));
};

// ============================================================================
// Contributor Role Handling
// ============================================================================

/**
 * Normalize initial contributor roles from various formats.
 */
export const normaliseInitialContributorRoles = (
    roles: (string | null | undefined)[] | Record<string, unknown> | string | null | undefined,
): ContributorRoleTag[] => {
    if (!roles) {
        return [];
    }

    const rawRoles = Array.isArray(roles) ? roles : typeof roles === 'string' ? [roles] : typeof roles === 'object' ? Object.values(roles) : [];

    const unique = new Set<string>();

    return rawRoles
        .map((role) => (typeof role === 'string' ? normaliseContributorRoleLabel(role) : ''))
        .filter((role) => role.length > 0)
        .filter((role) => {
            if (unique.has(role)) {
                return false;
            }
            unique.add(role);
            return true;
        })
        .map((role) => ({ value: role }));
};

// ============================================================================
// Empty Entry Creators
// ============================================================================

export const createEmptyPersonAuthor = (): PersonAuthorEntry => ({
    id: crypto.randomUUID(),
    type: 'person',
    orcid: '',
    firstName: '',
    lastName: '',
    email: '',
    website: '',
    isContact: false,
    affiliations: [],
    affiliationsInput: '',
    orcidVerified: false,
    orcidVerifiedAt: undefined,
});

export const createEmptyInstitutionAuthor = (): InstitutionAuthorEntry => ({
    id: crypto.randomUUID(),
    type: 'institution',
    institutionName: '',
    affiliations: [],
    affiliationsInput: '',
});

export const createEmptyPersonContributor = (): PersonContributorEntry => ({
    id: crypto.randomUUID(),
    type: 'person',
    roles: [],
    rolesInput: '',
    orcid: '',
    firstName: '',
    lastName: '',
    affiliations: [],
    affiliationsInput: '',
    orcidVerified: false,
    orcidVerifiedAt: undefined,
});

export const createEmptyInstitutionContributor = (): InstitutionContributorEntry => ({
    id: crypto.randomUUID(),
    type: 'institution',
    roles: [],
    rolesInput: '',
    institutionName: '',
    affiliations: [],
    affiliationsInput: '',
});

// ============================================================================
// Initial Data Mappers
// ============================================================================

/**
 * Map initial author data from backend/XML to AuthorEntry format.
 */
export const mapInitialAuthorToEntry = (author: InitialAuthor): AuthorEntry | null => {
    if (!author || typeof author !== 'object') {
        return null;
    }

    const affiliations = normaliseInitialAffiliations(author.affiliations ?? null);
    const affiliationsInput = affiliations.map((item) => item.value).join(',');

    if (author.type === 'institution') {
        const base = createEmptyInstitutionAuthor();

        return {
            ...base,
            institutionName: typeof author.institutionName === 'string' ? author.institutionName.trim() : '',
            affiliations,
            affiliationsInput,
        } satisfies InstitutionAuthorEntry;
    }

    const base = createEmptyPersonAuthor();

    return {
        ...base,
        orcid: normalizeOrcid(author.orcid),
        firstName: typeof author.firstName === 'string' ? author.firstName.trim() : '',
        lastName: typeof author.lastName === 'string' ? author.lastName.trim() : '',
        email: typeof author.email === 'string' ? author.email.trim() : '',
        website: normalizeWebsiteUrl(author.website),
        isContact: author.isContact === true || author.isContact === 'true',
        affiliations,
        affiliationsInput,
    } satisfies PersonAuthorEntry;
};

/**
 * Map initial contributor data from backend/XML to ContributorEntry format.
 */
export const mapInitialContributorToEntry = (contributor: InitialContributor): ContributorEntry | null => {
    if (!contributor || typeof contributor !== 'object') {
        return null;
    }

    const affiliations = normaliseInitialAffiliations(contributor.affiliations ?? null);
    const affiliationsInput = affiliations.map((item) => item.value).join(',');
    const roles = normaliseInitialContributorRoles(contributor.roles ?? null);
    const roleLabels = roles.map((role) => role.value);
    const rolesInput = roleLabels.join(', ');
    const resolvedType = inferContributorTypeFromRoles(contributor.type, roleLabels);

    if (resolvedType === 'institution') {
        const base = createEmptyInstitutionContributor();
        const institutionContributor = contributor as { type: 'institution'; institutionName?: string | null };

        return {
            ...base,
            institutionName: typeof institutionContributor.institutionName === 'string' ? institutionContributor.institutionName.trim() : '',
            affiliations,
            affiliationsInput,
            roles,
            rolesInput,
        } satisfies InstitutionContributorEntry;
    }

    const base = createEmptyPersonContributor();
    const personContributor = contributor as { orcid?: string | null; firstName?: string | null; lastName?: string | null };

    return {
        ...base,
        orcid: normalizeOrcid(personContributor.orcid),
        firstName: typeof personContributor.firstName === 'string' ? personContributor.firstName.trim() : '',
        lastName: typeof personContributor.lastName === 'string' ? personContributor.lastName.trim() : '',
        affiliations,
        affiliationsInput,
        roles,
        rolesInput,
    } satisfies PersonContributorEntry;
};

// ============================================================================
// Form State Validators
// ============================================================================

import type { DateEntry, LicenseEntry, TitleEntry } from '../types/datacite-form-types';

/**
 * Check if a new title can be added based on current state.
 */
export function canAddTitle(titles: TitleEntry[], maxTitles: number): boolean {
    return titles.length < maxTitles && titles.length > 0 && !!titles[titles.length - 1].title;
}

/**
 * Check if a new license can be added based on current state.
 */
export function canAddLicense(licenseEntries: LicenseEntry[], maxLicenses: number): boolean {
    return licenseEntries.length < maxLicenses && licenseEntries.length > 0 && !!licenseEntries[licenseEntries.length - 1].license;
}

/**
 * Check if a new date can be added based on current state.
 */
export function canAddDate(dates: DateEntry[], maxDates: number): boolean {
    return dates.length < maxDates && dates.length > 0 && (!!dates[dates.length - 1].startDate || !!dates[dates.length - 1].endDate);
}

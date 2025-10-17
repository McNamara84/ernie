import { useEffect, useMemo, useRef, useState } from 'react';

import axios from 'axios';

import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { validateAllFundingReferences } from '@/hooks/use-funding-reference-validation';
import { useRorAffiliations } from '@/hooks/use-ror-affiliations';
import { withBasePath } from '@/lib/base-path';
import { inferContributorTypeFromRoles, normaliseContributorRoleLabel } from '@/lib/contributors';
import { hasValidDateValue } from '@/lib/date-utils';
import type { Language, License, MSLLaboratory, RelatedIdentifier, ResourceType, Role, TitleType } from '@/types';
import type { AffiliationTag } from '@/types/affiliations';
import type { GCMDKeyword, SelectedKeyword } from '@/types/gcmd';
import { getVocabularyTypeFromScheme } from '@/types/gcmd';

import AuthorField, {
    type AuthorEntry,
    type InstitutionAuthorEntry,
    type PersonAuthorEntry,
} from './fields/author';
import ContributorField, {
    type ContributorEntry,
    type ContributorRoleTag,
    type InstitutionContributorEntry,
    type PersonContributorEntry,
} from './fields/contributor';
import ControlledVocabulariesField from './fields/controlled-vocabularies-field';
import DateField from './fields/date-field';
import DescriptionField, { type DescriptionEntry } from './fields/description-field';
import FreeKeywordsField from './fields/free-keywords-field';
import { type FundingReferenceEntry, FundingReferenceField } from './fields/funding-reference';
import InputField from './fields/input-field';
import LicenseField from './fields/license-field';
import MSLLaboratoriesField from './fields/msl-laboratories-field';
import { RelatedWorkField } from './fields/related-work';
import { SelectField } from './fields/select-field';
import SpatialTemporalCoverageField from './fields/spatial-temporal-coverage';
import { type SpatialTemporalCoverageEntry } from './fields/spatial-temporal-coverage/types';
import { type TagInputItem } from './fields/tag-input-field';
import TitleField from './fields/title-field';
import { resolveInitialLanguageCode } from './utils/language-resolver';

// Helper functions for normalizing ORCID and website URLs from old datasets
const normalizeOrcid = (orcid: string | null | undefined): string => {
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

const normalizeWebsiteUrl = (url: string | null | undefined): string => {
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

// Constants
const REQUIRED_DATE_TYPE = 'created' as const;

interface DataCiteFormData {
    doi: string;
    year: string;
    resourceType: string;
    version: string;
    language: string;
}

interface TitleEntry {
    id: string;
    title: string;
    titleType: string;
}

interface LicenseEntry {
    id: string;
    license: string;
}

interface DateEntry {
    id: string;
    startDate: string | null;
    endDate: string | null;
    dateType: string;
}

interface SerializedAffiliation {
    value: string;
    rorId: string | null;
}

type SerializedAuthor =
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

type SerializedContributor =
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

const createEmptyPersonAuthor = (): PersonAuthorEntry => ({
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
});

const createEmptyInstitutionAuthor = (): InstitutionAuthorEntry => ({
    id: crypto.randomUUID(),
    type: 'institution',
    institutionName: '',
    affiliations: [],
    affiliationsInput: '',
});

const createEmptyPersonContributor = (): PersonContributorEntry => ({
    id: crypto.randomUUID(),
    type: 'person',
    roles: [],
    rolesInput: '',
    orcid: '',
    firstName: '',
    lastName: '',
    affiliations: [],
    affiliationsInput: '',
});

const createEmptyInstitutionContributor = (): InstitutionContributorEntry => ({
    id: crypto.randomUUID(),
    type: 'institution',
    roles: [],
    rolesInput: '',
    institutionName: '',
    affiliations: [],
    affiliationsInput: '',
});

/**
 * Serializes and normalizes affiliations from an author or contributor entry.
 *
 * This function processes affiliation data by:
 * - Trimming whitespace from affiliation values and ROR IDs
 * - Filtering out empty affiliations (those with neither a value nor a ROR ID)
 * - Deduplicating affiliations based on the combination of value and ROR ID
 * - Normalizing affiliations so the value field is always populated (falls back to ROR ID if value is empty)
 *
 * @param entry - An author or contributor entry containing affiliations to serialize
 * @returns An array of deduplicated affiliation objects with value and rorId properties
 */
const serializeAffiliations = (
    entry: AuthorEntry | ContributorEntry
): SerializedAffiliation[] => {
    const seen = new Set<string>();

    return entry.affiliations
        .map((affiliation) => {
            const rawValue = affiliation.value.trim();
            const rawRorId = typeof affiliation.rorId === 'string' ? affiliation.rorId.trim() : '';

            if (!rawValue && !rawRorId) {
                return null;
            }

            const value = rawValue || rawRorId;
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

type InitialAffiliationInput = {
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

const normaliseInitialAffiliations = (
    affiliations?: (InitialAffiliationInput | null | undefined)[] | null,
): AffiliationTag[] => {
    if (!affiliations || !Array.isArray(affiliations)) {
        return [];
    }

    return affiliations
        .map((affiliation) => {
            if (!affiliation || typeof affiliation !== 'object') {
                return null;
            }

            // Try multiple property names for value
            const rawValue =
                ('value' in affiliation && typeof affiliation.value === 'string'
                    ? affiliation.value
                    : 'name' in affiliation && typeof (affiliation as Record<string, unknown>).name === 'string'
                      ? (affiliation as Record<string, unknown>).name as string
                      : ''
                ).trim();

            // Try multiple property names for rorId
            const rawRorId =
                ('rorId' in affiliation && typeof affiliation.rorId === 'string'
                    ? affiliation.rorId
                    : 'rorid' in affiliation && typeof (affiliation as Record<string, unknown>).rorid === 'string'
                      ? (affiliation as Record<string, unknown>).rorid as string
                      : 'identifier' in affiliation && typeof (affiliation as Record<string, unknown>).identifier === 'string'
                        ? (affiliation as Record<string, unknown>).identifier as string
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

const normaliseInitialContributorRoles = (
    roles: BaseInitialContributor['roles'],
): ContributorRoleTag[] => {
    if (!roles) {
        return [];
    }

    const rawRoles = Array.isArray(roles)
        ? roles
        : typeof roles === 'string'
          ? [roles]
          : typeof roles === 'object'
            ? Object.values(roles)
            : [];

    const unique = new Set<string>();

    return rawRoles
        .map((role) =>
            typeof role === 'string' ? normaliseContributorRoleLabel(role) : '',
        )
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

const mapInitialContributorToEntry = (
    contributor: InitialContributor,
): ContributorEntry | null => {
    if (!contributor || typeof contributor !== 'object') {
        return null;
    }

    const affiliations = normaliseInitialAffiliations(contributor.affiliations ?? null);
    // Keep affiliations as separate tags, not joined into one string
    const affiliationsInput = affiliations.map((item) => item.value).join(',');
    const roles = normaliseInitialContributorRoles(contributor.roles ?? null);
    const roleLabels = roles.map((role) => role.value);
    const rolesInput = roleLabels.join(', ');
    const resolvedType = inferContributorTypeFromRoles(contributor.type, roleLabels);

    if (resolvedType === 'institution') {
        const base = createEmptyInstitutionContributor();
        const institutionContributor = contributor as BaseInitialContributor & {
            type: 'institution';
            institutionName?: string | null;
        };

        return {
            ...base,
            institutionName:
                typeof institutionContributor.institutionName === 'string'
                    ? institutionContributor.institutionName.trim()
                    : '',
            affiliations,
            affiliationsInput,
            roles,
            rolesInput,
        } satisfies InstitutionContributorEntry;
    }

    const base = createEmptyPersonContributor();
    const personContributor = contributor as BaseInitialContributor & {
        type?: 'person';
        orcid?: string | null;
        firstName?: string | null;
        lastName?: string | null;
    };

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

const mapInitialAuthorToEntry = (author: InitialAuthor): AuthorEntry | null => {
    if (!author || typeof author !== 'object') {
        return null;
    }

    const affiliations = normaliseInitialAffiliations(author.affiliations ?? null);
    // Keep affiliations as separate tags, not joined into one string
    const affiliationsInput = affiliations.map((item) => item.value).join(',');

    if (author.type === 'institution') {
        const base = createEmptyInstitutionAuthor();

        return {
            ...base,
            institutionName:
                typeof author.institutionName === 'string'
                    ? author.institutionName.trim()
                    : '',
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

interface DataCiteFormProps {
    resourceTypes: ResourceType[];
    titleTypes: TitleType[];
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
    initialGcmdKeywords?: { id: string; path: string; text: string; scheme: string; schemeURI?: string; language?: string; isLegacy?: string }[];
    initialFreeKeywords?: string[];
    initialSpatialTemporalCoverages?: SpatialTemporalCoverageEntry[];
    initialRelatedWorks?: RelatedIdentifier[];
    initialFundingReferences?: FundingReferenceEntry[];
    initialMslLaboratories?: MSLLaboratory[];
}

export function canAddTitle(titles: TitleEntry[], maxTitles: number) {
    return (
        titles.length < maxTitles &&
        titles.length > 0 &&
        !!titles[titles.length - 1].title
    );
}

export function canAddLicense(
    licenseEntries: LicenseEntry[],
    maxLicenses: number,
) {
    return (
        licenseEntries.length < maxLicenses &&
        licenseEntries.length > 0 &&
        !!licenseEntries[licenseEntries.length - 1].license
    );
}

export function canAddDate(dates: DateEntry[], maxDates: number) {
    return (
        dates.length < maxDates &&
        dates.length > 0 &&
        (!!dates[dates.length - 1].startDate || !!dates[dates.length - 1].endDate)
    );
}

export default function DataCiteForm({
    resourceTypes,
    titleTypes,
    licenses,
    languages,
    contributorPersonRoles = [],
    contributorInstitutionRoles = [],
    authorRoles = [],
    maxTitles = 99,
    maxLicenses = 99,
    googleMapsApiKey,
    initialDoi = '',
    initialYear = '',
    initialVersion = '',
    initialLanguage = '',
    initialResourceType = '',
    initialTitles = [],
    initialLicenses = [],
    initialResourceId,
    initialAuthors = [],
    initialContributors = [],
    initialDescriptions = [],
    initialDates = [],
    initialGcmdKeywords = [],
    initialFreeKeywords = [],
    initialSpatialTemporalCoverages = [],
    initialMslLaboratories = [],
    initialRelatedWorks = [],
    initialFundingReferences = [],
}: DataCiteFormProps) {
    const MAX_TITLES = maxTitles;
    const MAX_LICENSES = maxLicenses;
    const MAX_DATES = 11;
    
    // Available date types according to DataCite schema with descriptions
    const dateTypes = [
        { 
            value: 'accepted', 
            label: 'Accepted',
            description: 'The date that the publisher accepted the resource into their system. To indicate the start of an embargo period, use Accepted or Submitted.'
        },
        { 
            value: 'available', 
            label: 'Available',
            description: 'The date the resource is made publicly available. May be a range. To indicate the end of an embargo period, use Available.'
        },
        { 
            value: 'copyrighted', 
            label: 'Copyrighted',
            description: 'The specific, documented date at which the resource receives a copyrighted status, if applicable.'
        },
        { 
            value: 'collected', 
            label: 'Collected',
            description: 'The date or date range in which the resource content was collected. To indicate precise or particular timeframes in which research was conducted.'
        },
        { 
            value: REQUIRED_DATE_TYPE, 
            label: 'Created',
            description: 'The date the resource itself was put together; this could refer to a timeframe in ancient history, a date range, or a single date for a final component. Recommended for discovery.'
        },
        { 
            value: 'issued', 
            label: 'Issued',
            description: 'The date that the resource is published or distributed, e.g., to a data centre.'
        },
        { 
            value: 'submitted', 
            label: 'Submitted',
            description: 'The date the creator submits the resource to the publisher. This could be different from Accepted if the publisher then applies a selection process. Recommended for discovery. To indicate the start of an embargo period, use Submitted or Accepted.'
        },
        { 
            value: 'updated', 
            label: 'Updated',
            description: 'The date of the last update to the resource, when the resource is being added to. May be a range.'
        },
        { 
            value: 'valid', 
            label: 'Valid',
            description: 'The date or date range during which the dataset or resource is accurate.'
        },
        { 
            value: 'withdrawn', 
            label: 'Withdrawn',
            description: 'The date the resource is removed. It is good practice to include a Description that indicates the reason for the retraction or withdrawal.'
        },
        { 
            value: 'other', 
            label: 'Other',
            description: 'Other date that does not fit into an existing category.'
        },
    ];
    
    const errorRef = useRef<HTMLDivElement | null>(null);
    const [form, setForm] = useState<DataCiteFormData>({
        doi: initialDoi,
        year: initialYear,
        resourceType: initialResourceType,
        version: initialVersion,
        language: resolveInitialLanguageCode(languages, initialLanguage),
    });

    const [titles, setTitles] = useState<TitleEntry[]>(
        initialTitles.length
            ? initialTitles.map((t) => ({
                  id: crypto.randomUUID(),
                  title: t.title,
                  titleType: t.titleType,
              }))
            : [{ id: crypto.randomUUID(), title: '', titleType: 'main-title' }],
    );

    const [licenseEntries, setLicenseEntries] = useState<LicenseEntry[]>(
        initialLicenses.length
            ? initialLicenses.map((l) => ({
                  id: crypto.randomUUID(),
                  license: l,
              }))
            : [{ id: crypto.randomUUID(), license: '' }],
    );

    const [authors, setAuthors] = useState<AuthorEntry[]>(() => {
        if (initialAuthors.length > 0) {
            const mapped = initialAuthors
                .map((author) => mapInitialAuthorToEntry(author))
                .filter((author): author is AuthorEntry => Boolean(author));

            if (mapped.length > 0) {
                return mapped;
            }
        }

        // Empty state - no authors initially
        return [];
    });
    const [contributors, setContributors] = useState<ContributorEntry[]>(() => {
        if (initialContributors.length > 0) {
            const mapped = initialContributors
                .map((contributor) => mapInitialContributorToEntry(contributor))
                .filter((contributor): contributor is ContributorEntry => Boolean(contributor));

            if (mapped.length > 0) {
                return mapped;
            }
        }

        // Empty state - no contributors initially
        return [];
    });
    const [descriptions, setDescriptions] = useState<DescriptionEntry[]>(() => {
        if (initialDescriptions && initialDescriptions.length > 0) {
            return initialDescriptions.map((desc) => ({
                type: desc.type as DescriptionEntry['type'],
                value: desc.description,
            }));
        }
        return [];
    });
    const [dates, setDates] = useState<DateEntry[]>(() => {
        if (initialDates && initialDates.length > 0) {
            return initialDates.map((date) => ({
                id: crypto.randomUUID(),
                dateType: date.dateType,
                startDate: date.startDate,
                endDate: date.endDate,
            }));
        }
        return [
            { id: crypto.randomUUID(), startDate: '', endDate: '', dateType: REQUIRED_DATE_TYPE },
        ];
    });

    const [gcmdKeywords, setGcmdKeywords] = useState<SelectedKeyword[]>(() => {
        if (initialGcmdKeywords && initialGcmdKeywords.length > 0) {
            return initialGcmdKeywords
                .filter((kw): kw is typeof kw & { scheme: string } => 
                    typeof kw.scheme === 'string' && kw.scheme.length > 0
                )
                .map((kw) => ({
                    id: kw.id,
                    text: kw.text,
                    path: kw.path,
                    language: ('language' in kw && typeof kw.language === 'string') ? kw.language : 'en',
                    scheme: kw.scheme,
                    schemeURI: ('schemeURI' in kw && typeof kw.schemeURI === 'string') ? kw.schemeURI : '',
                    isLegacy: kw.isLegacy === 'true', // String from URL params
                }));
        }
        return [];
    });
    const [freeKeywords, setFreeKeywords] = useState<TagInputItem[]>(() => {
        if (initialFreeKeywords && initialFreeKeywords.length > 0) {
            return initialFreeKeywords.map((keyword) => ({
                value: keyword,
            }));
        }
        return [];
    });
    const [spatialTemporalCoverages, setSpatialTemporalCoverages] = useState<
        SpatialTemporalCoverageEntry[]
    >(() => {
        if (initialSpatialTemporalCoverages && initialSpatialTemporalCoverages.length > 0) {
            return initialSpatialTemporalCoverages;
        }
        return [];
    });
    const [relatedWorks, setRelatedWorks] = useState<RelatedIdentifier[]>(() => {
        if (initialRelatedWorks && initialRelatedWorks.length > 0) {
            return initialRelatedWorks;
        }
        return [];
    });
    const [fundingReferences, setFundingReferences] = useState<FundingReferenceEntry[]>(() => {
        if (initialFundingReferences && initialFundingReferences.length > 0) {
            return initialFundingReferences;
        }
        return [];
    });
    const [mslLaboratories, setMslLaboratories] = useState<MSLLaboratory[]>(() => {
        if (initialMslLaboratories && initialMslLaboratories.length > 0) {
            return initialMslLaboratories;
        }
        return [];
    });
    const [openAccordionItems, setOpenAccordionItems] = useState<string[]>([
        'resource-info',
        'authors',
        'licenses-rights',
        'contributors',
        'descriptions',
        'controlled-vocabularies',
        'free-keywords',
        'spatial-temporal-coverage',
        'dates',
        'related-work',
        'funding-references',
    ]);
    const [gcmdVocabularies, setGcmdVocabularies] = useState<{
        science: GCMDKeyword[];
        platforms: GCMDKeyword[];
        instruments: GCMDKeyword[];
        msl: GCMDKeyword[];
    }>({
        science: [],
        platforms: [],
        instruments: [],
        msl: [],
    });
    const [isLoadingVocabularies, setIsLoadingVocabularies] = useState(true);

    // Load GCMD vocabularies from web routes on mount
    useEffect(() => {
        const loadVocabularies = async () => {
            try {
                const [scienceRes, platformsRes, instrumentsRes] = await Promise.all([
                    fetch(withBasePath('/vocabularies/gcmd-science-keywords')),
                    fetch(withBasePath('/vocabularies/gcmd-platforms')),
                    fetch(withBasePath('/vocabularies/gcmd-instruments')),
                ]);

                if (!scienceRes.ok || !platformsRes.ok || !instrumentsRes.ok) {
                    console.error('Failed to load GCMD vocabularies', {
                        science: scienceRes.status,
                        platforms: platformsRes.status,
                        instruments: instrumentsRes.status,
                    });
                    return;
                }

                const [scienceData, platformsData, instrumentsData] = await Promise.all([
                    scienceRes.json(),
                    platformsRes.json(),
                    instrumentsRes.json(),
                ]);

                console.log('Loaded GCMD vocabularies:', {
                    science: scienceData.data?.length || 0,
                    platforms: platformsData.data?.length || 0,
                    instruments: instrumentsData.data?.length || 0,
                });

                setGcmdVocabularies({
                    science: scienceData.data || [],
                    platforms: platformsData.data || [],
                    instruments: instrumentsData.data || [],
                    msl: [], // MSL will be loaded conditionally
                });
            } catch (error) {
                console.error('Error loading GCMD vocabularies:', error);
            } finally {
                setIsLoadingVocabularies(false);
            }
        };

        void loadVocabularies();
    }, []);

    // Check if MSL section should be shown based on Free Keywords
    const shouldShowMSLSection = useMemo(() => {
        const keywords = freeKeywords.map((k) => k.value.toLowerCase());
        const triggers = ['epos', 'multi-scale laboratories', 'multi scale laboratories', 'msl'];

        return keywords.some((keyword) => triggers.some((trigger) => keyword.includes(trigger)));
    }, [freeKeywords]);

    // Load MSL vocabulary when MSL section becomes visible
    useEffect(() => {
        if (shouldShowMSLSection && gcmdVocabularies.msl.length === 0) {
            const loadMslVocabulary = async () => {
                try {
                    const response = await fetch(withBasePath('/vocabularies/msl'));
                    
                    if (!response.ok) {
                        console.error('Failed to load MSL vocabulary', response.status);
                        return;
                    }

                    const data = await response.json();
                    console.log('Loaded MSL vocabulary:', data.length || 0, 'root nodes');

                    setGcmdVocabularies((prev) => ({
                        ...prev,
                        msl: data || [],
                    }));
                } catch (error) {
                    console.error('Error loading MSL vocabulary:', error);
                }
            };

            void loadMslVocabulary();
        }
    }, [shouldShowMSLSection, gcmdVocabularies.msl.length]);

    // Automatically open MSL section when it becomes visible
    useEffect(() => {
        if (shouldShowMSLSection && !openAccordionItems.includes('msl-laboratories')) {
            setOpenAccordionItems((prev) => [...prev, 'msl-laboratories']);
        } else if (!shouldShowMSLSection && openAccordionItems.includes('msl-laboratories')) {
            setOpenAccordionItems((prev) => prev.filter((item) => item !== 'msl-laboratories'));
        }
    }, [shouldShowMSLSection, openAccordionItems]);
    
    const contributorPersonRoleNames = useMemo(
        () => contributorPersonRoles.map((role) => role.name),
        [contributorPersonRoles],
    );
    const contributorInstitutionRoleNames = useMemo(
        () => contributorInstitutionRoles.map((role) => role.name),
        [contributorInstitutionRoles],
    );
    const authorRoleNames = useMemo(
        () =>
            authorRoles
                .map((role) => role.name.trim())
                .filter((name): name is string => name.length > 0),
        [authorRoles],
    );
    const authorRoleSummary = useMemo(() => {
        if (authorRoleNames.length === 0) {
            return '';
        }

        if (authorRoleNames.length === 1) {
            return authorRoleNames[0];
        }

        if (authorRoleNames.length === 2) {
            return `${authorRoleNames[0]} and ${authorRoleNames[1]}`;
        }

        const allButLast = authorRoleNames.slice(0, -1).join(', ');
        const last = authorRoleNames[authorRoleNames.length - 1];
        return `${allButLast}, and ${last}`;
    }, [authorRoleNames]);
    const authorRolesDescriptionId =
        authorRoleNames.length > 0 ? 'author-roles-description' : undefined;
    const { suggestions: affiliationSuggestions } = useRorAffiliations();

    const [isSaving, setIsSaving] = useState(false);
    const [showSuccessModal, setShowSuccessModal] = useState(false);
    const [successMessage, setSuccessMessage] = useState('Successfully saved resource.');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [validationErrors, setValidationErrors] = useState<string[]>([]);

    const areRequiredFieldsFilled = useMemo(() => {
        const mainTitleEntry = titles.find((entry) => entry.titleType === 'main-title');
        const mainTitleFilled = Boolean(mainTitleEntry?.title.trim());
        const yearFilled = Boolean(form.year?.trim());
        const resourceTypeSelected = Boolean(form.resourceType);
        const languageSelected = Boolean(form.language);
        const primaryLicenseFilled = Boolean(licenseEntries[0]?.license?.trim());
        const authorsValid =
            authors.length > 0 &&
            authors.every((author) => {
                if (author.type === 'person') {
                    const hasLastName = Boolean(author.lastName.trim());
                    const contactValid = !author.isContact || Boolean(author.email.trim());
                    return hasLastName && contactValid;
                }

                return Boolean(author.institutionName.trim());
            });
        const abstractFilled = descriptions.some(
            (desc) => desc.type === 'Abstract' && desc.value.trim() !== '',
        );
        const dateCreatedFilled = dates.some(
            (date) => date.dateType === REQUIRED_DATE_TYPE && hasValidDateValue(date),
        );

        return (
            mainTitleFilled &&
            yearFilled &&
            resourceTypeSelected &&
            languageSelected &&
            primaryLicenseFilled &&
            authorsValid &&
            abstractFilled &&
            dateCreatedFilled
        );
    }, [authors, descriptions, dates, form.language, form.resourceType, form.year, licenseEntries, titles]);

    // Check if there are any legacy MSL keywords that need to be replaced
    const hasLegacyKeywords = useMemo(() => {
        return gcmdKeywords.some(kw => kw.isLegacy === true);
    }, [gcmdKeywords]);

    const handleChange = (field: keyof DataCiteFormData, value: string) => {
        setForm((prev) => ({ ...prev, [field]: value }));
    };

    const handleTitleChange = (
        index: number,
        field: keyof Omit<TitleEntry, 'id'>,
        value: string,
    ) => {
        setTitles((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], [field]: value };
            return next;
        });
    };

    const addTitle = () => {
        if (titles.length >= MAX_TITLES) return;
        const defaultType = titleTypes.find((t) => t.slug !== 'main-title')?.slug ?? '';
        setTitles((prev) => [
            ...prev,
            { id: crypto.randomUUID(), title: '', titleType: defaultType },
        ]);
    };

    const removeTitle = (index: number) => {
        setTitles((prev) => prev.filter((_, i) => i !== index));
    };

    const mainTitleUsed = titles.some((t) => t.titleType === 'main-title');

    const handleLicenseChange = (index: number, value: string) => {
        setLicenseEntries((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], license: value };
            return next;
        });
    };

    const addLicense = () => {
        if (licenseEntries.length >= MAX_LICENSES) return;
        setLicenseEntries((prev) => [
            ...prev,
            { id: crypto.randomUUID(), license: '' },
        ]);
    };

    const removeLicense = (index: number) => {
        setLicenseEntries((prev) => prev.filter((_, i) => i !== index));
    };

    const handleDateChange = (
        index: number,
        field: keyof Omit<DateEntry, 'id'>,
        value: string,
    ) => {
        setDates((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], [field]: value };
            return next;
        });
    };

    const addDate = () => {
        if (dates.length >= MAX_DATES) return;
        // Find the first unused date type or default to 'other'
        const usedTypes = new Set(dates.map((d) => d.dateType));
        const availableType = dateTypes.find((dt) => !usedTypes.has(dt.value))?.value ?? 'other';
        setDates((prev) => [
            ...prev,
            { id: crypto.randomUUID(), startDate: '', endDate: '', dateType: availableType },
        ]);
    };

    const removeDate = (index: number) => {
        setDates((prev) => prev.filter((_, i) => i !== index));
    };

    useEffect(() => {
        if (errorMessage && errorRef.current) {
            errorRef.current.focus();
        }
    }, [errorMessage]);

    const saveUrl = useMemo(() => withBasePath('/editor/resources'), []);

    const resolvedResourceId = useMemo(() => {
        if (!initialResourceId) {
            return null;
        }

        const trimmed = initialResourceId.trim();

        if (!trimmed) {
            return null;
        }

        const parsed = Number(trimmed);

        return Number.isFinite(parsed) ? parsed : null;
    }, [initialResourceId]);

    const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setIsSaving(true);
        setErrorMessage(null);
        setValidationErrors([]);

        // Client-side validation for funding references
        if (!validateAllFundingReferences(fundingReferences)) {
            setValidationErrors(['Please fix the validation errors in the Funding References section before submitting.']);
            setIsSaving(false);
            // Scroll to funding references section
            const fundingSection = document.getElementById('funding-references-section');
            if (fundingSection) {
                fundingSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            return;
        }

        const serializedAuthors: SerializedAuthor[] = authors.map((author, index) => {
            const affiliations = serializeAffiliations(author);

            if (author.type === 'person') {
                const orcid = author.orcid.trim();
                const firstName = author.firstName.trim();
                const lastName = author.lastName.trim();
                const email = author.email.trim();
                const website = author.website.trim();

                return {
                    type: 'person',
                    orcid: orcid || null,
                    firstName: firstName || null,
                    lastName,
                    email: author.isContact && email ? email : null,
                    website: author.isContact && website ? website : null,
                    isContact: author.isContact,
                    affiliations,
                    position: index,
                } satisfies SerializedAuthor;
            }

            const institutionName = author.institutionName.trim();
            // Get ROR ID from first affiliation that has one
            const rorId = author.affiliations.find((aff) => aff.rorId)?.rorId?.trim() || null;

            return {
                type: 'institution',
                institutionName,
                rorId,
                affiliations,
                position: index,
            } satisfies SerializedAuthor;
        });

        const serializedContributors: SerializedContributor[] = contributors
            .filter((contributor) => {
                // Filter out empty contributors
                if (contributor.type === 'person') {
                    const hasName = contributor.lastName.trim() !== '';
                    const hasRoles = contributor.roles.length > 0;
                    return hasName || hasRoles;
                }
                const hasInstitution = contributor.institutionName.trim() !== '';
                const hasRoles = contributor.roles.length > 0;
                return hasInstitution || hasRoles;
            })
            .map((contributor, index) => {
                const affiliations = serializeAffiliations(contributor);
                const roles = contributor.roles.map((role) => role.value);

                if (contributor.type === 'person') {
                    const orcid = contributor.orcid.trim();
                    const firstName = contributor.firstName.trim();
                    const lastName = contributor.lastName.trim();

                    return {
                        type: 'person',
                        orcid: orcid || null,
                        firstName: firstName || null,
                        lastName,
                        roles,
                        affiliations,
                        position: index,
                    } satisfies SerializedContributor;
                }

                const institutionName = contributor.institutionName.trim();

                return {
                    type: 'institution',
                    institutionName,
                    roles,
                    affiliations,
                    position: index,
                } satisfies SerializedContributor;
            });

        const payload: {
            doi: string | null;
            year: number | null;
            resourceType: number | null;
            version: string | null;
            language: string;
            titles: { title: string; titleType: string }[];
            licenses: string[];
            authors: SerializedAuthor[];
            contributors: SerializedContributor[];
            mslLaboratories: {
                identifier: string;
                name: string;
                affiliation_name: string;
                affiliation_ror: string | null;
            }[];
            descriptions: { descriptionType: string; description: string }[];
            dates: { dateType: string; startDate: string | null; endDate: string | null }[];
            freeKeywords: string[];
            gcmdKeywords: {
                id: string;
                text: string;
                path: string;
                language: string;
                scheme: string;
                schemeURI: string;
                vocabularyType: string;
            }[];
            spatialTemporalCoverages: {
                latMin: string;
                latMax: string;
                lonMin: string;
                lonMax: string;
                startDate: string;
                endDate: string;
                startTime: string;
                endTime: string;
                timezone: string;
                description: string;
            }[];
            relatedIdentifiers: {
                identifier: string;
                identifierType: string;
                relationType: string;
            }[];
            fundingReferences: {
                funderName: string;
                funderIdentifier: string;
                funderIdentifierType: string | null;
                awardNumber: string;
                awardUri: string;
                awardTitle: string;
            }[];
            resourceId?: number;
        } = {
            doi: form.doi?.trim() || null,
            year: form.year ? Number(form.year) : null,
            resourceType: form.resourceType ? Number(form.resourceType) : null,
            version: form.version?.trim() || null,
            language: form.language,
            titles: titles.map((entry) => ({
                title: entry.title,
                titleType: entry.titleType,
            })),
            licenses: licenseEntries
                .map((entry) => entry.license)
                .filter((license): license is string => Boolean(license)),
            authors: serializedAuthors,
            contributors: serializedContributors,
            mslLaboratories: mslLaboratories.map((lab) => ({
                identifier: lab.identifier,
                name: lab.name,
                affiliation_name: lab.affiliation_name,
                affiliation_ror: lab.affiliation_ror || null,
            })),
            descriptions: descriptions
                .filter((desc) => desc.value.trim() !== '')
                .map((desc) => ({
                    descriptionType: desc.type,
                    description: desc.value.trim(),
                })),
            dates: dates
                .filter(hasValidDateValue)
                .map((date) => ({
                    dateType: date.dateType,
                    startDate: date.startDate || null,
                    endDate: date.endDate || null,
                })),
            freeKeywords: freeKeywords
                .map((kw) => kw.value.trim())
                .filter((kw) => kw.length > 0),
            gcmdKeywords: gcmdKeywords.map((kw) => ({
                id: kw.id,
                text: kw.text,
                path: kw.path,
                language: kw.language,
                scheme: kw.scheme,
                schemeURI: kw.schemeURI,
                vocabularyType: getVocabularyTypeFromScheme(kw.scheme),
            })),
            spatialTemporalCoverages: spatialTemporalCoverages.map((coverage) => ({
                latMin: coverage.latMin,
                latMax: coverage.latMax,
                lonMin: coverage.lonMin,
                lonMax: coverage.lonMax,
                startDate: coverage.startDate,
                endDate: coverage.endDate,
                startTime: coverage.startTime,
                endTime: coverage.endTime,
                timezone: coverage.timezone,
                description: coverage.description,
            })),
            relatedIdentifiers: relatedWorks.map((rw) => ({
                identifier: rw.identifier,
                identifierType: rw.identifier_type,
                relationType: rw.relation_type,
            })),
            fundingReferences: fundingReferences.map((funding) => ({
                funderName: funding.funderName,
                funderIdentifier: funding.funderIdentifier,
                funderIdentifierType: funding.funderIdentifierType,
                awardNumber: funding.awardNumber,
                awardUri: funding.awardUri,
                awardTitle: funding.awardTitle,
            })),
        };

        if (resolvedResourceId !== null) {
            payload.resourceId = resolvedResourceId;
        }

        try {
            const response = await axios.post(saveUrl, payload, {
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
            });

            const data = response.data as { message?: string } | null;
            setSuccessMessage(data?.message || 'Successfully saved resource.');
            setShowSuccessModal(true);
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const response = error.response;
                
                if (response?.status === 419) {
                    console.error('CSRF token mismatch detected');
                    setErrorMessage(
                        'Your session has expired. Please refresh the page and try again.',
                    );
                    // The axios interceptor in app.tsx will handle the page reload
                    return;
                }

                if (response) {
                    const defaultError = 'Unable to save resource. Please review the highlighted issues.';
                    const parsed = response.data as { message?: string; errors?: Record<string, string[]> } | null;
                    const messages = parsed?.errors
                        ? Object.values(parsed.errors).flat().map((message) => String(message))
                        : [];

                    setValidationErrors(messages);
                    setErrorMessage(parsed?.message || defaultError);
                    return;
                }
            }
            
            console.error('Failed to save resource', error);
            setErrorMessage('A network error prevented saving the resource. Please try again.');
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            {errorMessage && (
                <div
                    ref={errorRef}
                    tabIndex={-1}
                    className="rounded-md border border-destructive bg-destructive/10 p-4 text-destructive"
                    role="alert"
                    aria-live="assertive"
                >
                    <p className="font-semibold">{errorMessage}</p>
                    {validationErrors.length > 0 && (
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm">
                            {validationErrors.map((message, index) => (
                                <li key={`${message}-${index}`}>{message}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            <Accordion
                type="multiple"
                value={openAccordionItems}
                onValueChange={setOpenAccordionItems}
                className="w-full"
            >
                <AccordionItem value="resource-info">
                    <AccordionTrigger>Resource Information</AccordionTrigger>
                    <AccordionContent className="space-y-6">
                        <div className="grid gap-4 md:grid-cols-12">
                            <InputField
                                id="doi"
                                label="DOI"
                                value={form.doi || ''}
                                onChange={(e) => handleChange('doi', e.target.value)}
                                placeholder="10.xxxx/xxxxx"
                                className="md:col-span-3"
                            />
                            <InputField
                                id="year"
                                type="number"
                                label="Year"
                                value={form.year || ''}
                                onChange={(e) => handleChange('year', e.target.value)}
                                placeholder="2024"
                                className="md:col-span-2"
                                required
                            />
                            <SelectField
                                id="resourceType"
                                label="Resource Type"
                                value={form.resourceType || ''}
                                onValueChange={(val) => handleChange('resourceType', val)}
                                options={resourceTypes.map((type) => ({
                                    value: String(type.id),
                                    label: type.name,
                                }))}
                                className="md:col-span-4"
                                required
                            />
                            <InputField
                                id="version"
                                label="Version"
                                value={form.version || ''}
                                onChange={(e) => handleChange('version', e.target.value)}
                                placeholder="1.0"
                                className="md:col-span-1"
                            />
                            <SelectField
                                id="language"
                                label="Language of Data"
                                value={form.language || ''}
                                onValueChange={(val) => handleChange('language', val)}
                                options={languages.map((l) => ({
                                    value: l.code,
                                    label: l.name,
                                }))}
                                className="md:col-span-2"
                                required
                            />
                        </div>
                        <div className="space-y-4 mt-3">
                            {titles.map((entry, index) => (
                                <TitleField
                                    key={entry.id}
                                    id={entry.id}
                                    title={entry.title}
                                    titleType={entry.titleType}
                                    options={titleTypes
                                        .filter(
                                            (t) =>
                                                t.slug !== 'main-title' ||
                                                !mainTitleUsed ||
                                                entry.titleType === 'main-title',
                                        )
                                        .map((t) => ({ value: t.slug, label: t.name }))}
                                    onTitleChange={(val) =>
                                        handleTitleChange(index, 'title', val)
                                    }
                                    onTypeChange={(val) =>
                                        handleTitleChange(index, 'titleType', val)
                                    }
                                    onAdd={addTitle}
                                    onRemove={() => removeTitle(index)}
                                    isFirst={index === 0}
                                    canAdd={canAddTitle(titles, MAX_TITLES)}
                                />
                            ))}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="licenses-rights">
                    <AccordionTrigger>Licenses and Rights</AccordionTrigger>
                    <AccordionContent>
                        <div className="space-y-4">
                            {licenseEntries.map((entry, index) => (
                                <LicenseField
                                    key={entry.id}
                                    id={entry.id}
                                    license={entry.license}
                                    options={licenses.map((l) => ({
                                        value: l.identifier,
                                        label: l.name,
                                    }))}
                                    onLicenseChange={(val) =>
                                        handleLicenseChange(index, val)
                                    }
                                    onAdd={addLicense}
                                    onRemove={() => removeLicense(index)}
                                    isFirst={index === 0}
                                    canAdd={canAddLicense(
                                        licenseEntries,
                                        MAX_LICENSES,
                                    )}
                                    required={index === 0}
                                />
                            ))}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="authors">
                    <AccordionTrigger>Authors</AccordionTrigger>
                    <AccordionContent>
                        {authorRoleNames.length > 0 && (
                            <p
                                id={authorRolesDescriptionId}
                                className="mb-4 text-sm text-muted-foreground"
                                data-testid="author-roles-availability"
                            >
                                {`The available author ${
                                    authorRoleNames.length === 1 ? 'role is' : 'roles are'
                                } ${authorRoleSummary}.`}
                            </p>
                        )}
                        <AuthorField
                            authors={authors}
                            onChange={setAuthors}
                            affiliationSuggestions={affiliationSuggestions}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="contributors">
                    <AccordionTrigger>Contributors</AccordionTrigger>
                    <AccordionContent>
                        <ContributorField
                            contributors={contributors}
                            onChange={setContributors}
                            affiliationSuggestions={affiliationSuggestions}
                            personRoleOptions={contributorPersonRoleNames}
                            institutionRoleOptions={contributorInstitutionRoleNames}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="descriptions">
                    <AccordionTrigger>Descriptions</AccordionTrigger>
                    <AccordionContent>
                        <DescriptionField
                            descriptions={descriptions}
                            onChange={setDescriptions}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="controlled-vocabularies">
                    <AccordionTrigger>Controlled Vocabularies</AccordionTrigger>
                    <AccordionContent>
                        {isLoadingVocabularies ? (
                            <div className="text-center py-8 text-muted-foreground">
                                Loading vocabularies...
                            </div>
                        ) : (
                            <ControlledVocabulariesField
                                scienceKeywords={gcmdVocabularies.science}
                                platforms={gcmdVocabularies.platforms}
                                instruments={gcmdVocabularies.instruments}
                                mslVocabulary={gcmdVocabularies.msl}
                                selectedKeywords={gcmdKeywords}
                                onChange={setGcmdKeywords}
                                showMslTab={shouldShowMSLSection}
                            />
                        )}
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="free-keywords">
                    <AccordionTrigger>Free Keywords</AccordionTrigger>
                    <AccordionContent>
                        <FreeKeywordsField
                            keywords={freeKeywords}
                            onChange={setFreeKeywords}
                        />
                    </AccordionContent>
                </AccordionItem>
                {shouldShowMSLSection && (
                    <AccordionItem value="msl-laboratories">
                        <AccordionTrigger>
                            <div className="flex items-center gap-2">
                                <span>🔬 Originating Multi-Scale Laboratories</span>
                                <span className="rounded-md bg-secondary px-2 py-0.5 text-xs font-medium">
                                    EPOS/MSL
                                </span>
                            </div>
                        </AccordionTrigger>
                        <AccordionContent>
                            <MSLLaboratoriesField
                                selectedLaboratories={mslLaboratories}
                                onChange={setMslLaboratories}
                            />
                        </AccordionContent>
                    </AccordionItem>
                )}
                <AccordionItem value="spatial-temporal-coverage">
                    <AccordionTrigger>Spatial and Temporal Coverage</AccordionTrigger>
                    <AccordionContent>
                        <SpatialTemporalCoverageField
                            coverages={spatialTemporalCoverages}
                            apiKey={googleMapsApiKey}
                            onChange={setSpatialTemporalCoverages}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="dates">
                    <AccordionTrigger>Dates</AccordionTrigger>
                    <AccordionContent>
                        <div className="space-y-4">
                            {dates.map((entry, index) => {
                                const selectedDateType = dateTypes.find(dt => dt.value === entry.dateType);
                                return (
                                    <DateField
                                        key={entry.id}
                                        id={entry.id}
                                        startDate={entry.startDate}
                                        endDate={entry.endDate}
                                        dateType={entry.dateType}
                                        dateTypeDescription={selectedDateType?.description}
                                        options={dateTypes.filter(
                                            (dt) =>
                                                dt.value === entry.dateType ||
                                                !dates.some((d) => d.dateType === dt.value),
                                        )}
                                        onStartDateChange={(val) =>
                                            handleDateChange(index, 'startDate', val)
                                        }
                                        onEndDateChange={(val) =>
                                            handleDateChange(index, 'endDate', val)
                                        }
                                        onTypeChange={(val) =>
                                            handleDateChange(index, 'dateType', val)
                                        }
                                        onAdd={addDate}
                                        onRemove={() => removeDate(index)}
                                        isFirst={index === 0}
                                        canAdd={canAddDate(dates, MAX_DATES)}
                                    />
                                );
                            })}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="related-work">
                    <AccordionTrigger>Related Work</AccordionTrigger>
                    <AccordionContent>
                        <RelatedWorkField
                            relatedWorks={relatedWorks}
                            onChange={setRelatedWorks}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="funding-references">
                    <AccordionTrigger>Funding References</AccordionTrigger>
                    <AccordionContent id="funding-references-section">
                        <FundingReferenceField
                            value={fundingReferences}
                            onChange={setFundingReferences}
                        />
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
            {hasLegacyKeywords && (
                <div className="rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4">
                    <div className="flex items-start gap-3">
                        <span className="text-amber-600 dark:text-amber-400 text-xl">⚠️</span>
                        <div>
                            <h3 className="font-semibold text-amber-900 dark:text-amber-100 mb-1">
                                Legacy Keywords Detected
                            </h3>
                            <p className="text-sm text-amber-800 dark:text-amber-200">
                                This dataset contains MSL keywords from the old database that don't exist in the current vocabulary.
                                Please review the highlighted keywords in the "Controlled Vocabularies" section and replace them with keywords from the current MSL vocabulary before saving.
                            </p>
                        </div>
                    </div>
                </div>
            )}
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={isSaving || !areRequiredFieldsFilled || hasLegacyKeywords}
                    aria-busy={isSaving}
                    aria-disabled={isSaving || !areRequiredFieldsFilled || hasLegacyKeywords}
                    title={hasLegacyKeywords ? "Please replace all legacy keywords before saving" : undefined}
                >
                    {isSaving ? 'Saving…' : 'Save to database'}
                </Button>
            </div>
            <Dialog open={showSuccessModal} onOpenChange={setShowSuccessModal}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Successfully saved resource</DialogTitle>
                        <DialogDescription>
                            {successMessage}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" onClick={() => setShowSuccessModal(false)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </form>
    );
}

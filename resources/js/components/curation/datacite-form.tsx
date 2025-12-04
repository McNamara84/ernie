import axios from 'axios';
import { AlertCircle, CheckCircle, Circle, Plus } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

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
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFormValidation, type ValidationRule } from '@/hooks/use-form-validation';
import { validateAllFundingReferences } from '@/hooks/use-funding-reference-validation';
import { useRorAffiliations } from '@/hooks/use-ror-affiliations';
import { withBasePath } from '@/lib/base-path';
import { inferContributorTypeFromRoles, normaliseContributorRoleLabel } from '@/lib/contributors';
import { hasValidDateValue } from '@/lib/date-utils';
import type { DateType, Language, License, MSLLaboratory, RelatedIdentifier, ResourceType, Role, TitleType } from '@/types';
import type { AffiliationTag } from '@/types/affiliations';
import type { GCMDKeyword, SelectedKeyword } from '@/types/gcmd';
import { getVocabularyTypeFromScheme } from '@/types/gcmd';
import {
    validateDate,
    validateDOIFormat,
    validateRequired,
    validateSemanticVersion,
    validateTextLength,
    validateTitleUniqueness,
    validateYear,
} from '@/utils/validation-rules';

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

// Constants - Note: 'created' and 'updated' date types are now automatically managed by the backend

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
    dateTypes,
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
    
    // Date types that are automatically managed by the system and not editable by users
    // This is a constant array that never changes, so no useMemo needed
    const AUTO_MANAGED_DATE_TYPES = ['created', 'updated'] as const;
    
    // MAX_DATES excludes auto-managed types since users can't select them
    // Simple calculation - dateTypes is stable from props, no memoization needed
    const MAX_DATES = dateTypes.filter(
        dt => !AUTO_MANAGED_DATE_TYPES.includes(dt.slug as typeof AUTO_MANAGED_DATE_TYPES[number])
    ).length;
    
    // Transform dateTypes prop to the format used by the form
    // Note: 'Created' and 'Updated' are excluded as they are automatically managed
    const dateTypeOptions = useMemo(
        () => dateTypes.map((dt) => ({
            value: dt.slug,
            label: dt.name,
            description: dt.description ?? '',
        })),
        [dateTypes]
    );
    
    const errorRef = useRef<HTMLDivElement | null>(null);
    
    // Refs for accordion sections (for auto-scroll on validation errors)
    const resourceInfoRef = useRef<HTMLDivElement | null>(null);
    const licensesRef = useRef<HTMLDivElement | null>(null);
    const authorsRef = useRef<HTMLDivElement | null>(null);
    const descriptionsRef = useRef<HTMLDivElement | null>(null);
    const datesRef = useRef<HTMLDivElement | null>(null);
    const controlledVocabulariesRef = useRef<HTMLDivElement | null>(null);
    
    // Tracking refs for MSL notification
    const hasNotifiedMslUnlock = useRef<boolean>(false);
    const hasInitialMslTriggers = useRef<boolean>(false);
    
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
            // Filter out auto-managed date types ('created' and 'updated')
            // These are now automatically handled by the backend
            const autoManagedTypes: readonly string[] = AUTO_MANAGED_DATE_TYPES;
            return initialDates
                .filter((date) => !autoManagedTypes.includes(date.dateType.toLowerCase()))
                .map((date) => ({
                    id: crypto.randomUUID(),
                    dateType: date.dateType,
                    startDate: date.startDate,
                    endDate: date.endDate,
                }));
        }
        // Start with empty dates array - 'created' and 'updated' are auto-managed
        return [];
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
    
    // Check if initial free keywords contain MSL/EPOS triggers (to prevent notification on data load)
    useEffect(() => {
        if (initialFreeKeywords && initialFreeKeywords.length > 0) {
            const triggers = ['epos', 'multi-scale laboratories', 'multi scale laboratories', 'msl'];
            const hasInitialTriggers = initialFreeKeywords.some((keyword) => 
                triggers.some((trigger) => keyword.toLowerCase().includes(trigger))
            );
            hasInitialMslTriggers.current = hasInitialTriggers;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []); // Run only once on mount - initialFreeKeywords intentionally excluded
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
    
    // State to trigger auto-switch to MSL tab when it becomes available
    const [shouldAutoSwitchToMsl, setAutoSwitchToMslState] = useState<boolean>(false);
    
    // Stable callback for setting auto-switch state
    const setShouldAutoSwitchToMsl = useCallback((value: boolean) => {
        setAutoSwitchToMslState(value);
    }, []);

    // Form validation hook
    const { validateField, markFieldTouched, getFieldState, getFieldMessages } = useFormValidation();

    // Helper to handle field blur: mark as touched AND trigger validation
    const handleFieldBlur = (fieldId: string, value: unknown, rules: ValidationRule[]) => {
        markFieldTouched(fieldId);
        validateField({
            fieldId,
            value,
            rules,
            formData: form,
        });
    };

    // DOI validation rules
    const doiValidationRules: ValidationRule[] = [
        {
            validate: (value) => {
                if (!value || String(value).trim() === '') {
                    return null; // DOI is optional at this stage
                }
                const result = validateDOIFormat(String(value));
                if (!result.isValid) {
                    return { severity: 'error', message: result.error! };
                }
                return null;
            },
        },
        // TODO: Add async DOI registration check in separate effect
    ];

    // Year validation rules
    const yearValidationRules: ValidationRule[] = [
        {
            validate: (value) => {
                const requiredResult = validateRequired(String(value || ''), 'Year');
                if (!requiredResult.isValid) {
                    return { severity: 'error', message: requiredResult.error! };
                }

                const yearResult = validateYear(String(value));
                if (!yearResult.isValid) {
                    return { severity: 'error', message: yearResult.error! };
                }

                return null;
            },
        },
    ];

    // Resource Type validation rules
    const resourceTypeValidationRules: ValidationRule[] = [
        {
            validate: (value) => {
                const result = validateRequired(String(value || ''), 'Resource Type');
                if (!result.isValid) {
                    return { severity: 'error', message: result.error! };
                }
                return null;
            },
        },
    ];

    // Language validation rules
    const languageValidationRules: ValidationRule[] = [
        {
            validate: (value) => {
                const result = validateRequired(String(value || ''), 'Language');
                if (!result.isValid) {
                    return { severity: 'error', message: result.error! };
                }
                return null;
            },
        },
    ];

    // Version validation rules (optional but must be semantic if provided)
    const versionValidationRules: ValidationRule[] = [
        {
            validate: (value) => {
                if (!value || String(value).trim() === '') {
                    return null; // Version is optional
                }
                const result = validateSemanticVersion(String(value));
                if (!result.isValid) {
                    return { severity: 'error', message: result.error! };
                }
                return null;
            },
        },
    ];

    // Title validation rules
    const createTitleValidationRules = (
        index: number,
        titleType: string,
        allTitles: TitleEntry[],
    ): ValidationRule[] => [
        {
            validate: (value) => {
                const titleValue = String(value || '');

                // Main title is required
                if (titleType === 'main-title') {
                    const requiredResult = validateRequired(titleValue, 'Main title');
                    if (!requiredResult.isValid) {
                        return { severity: 'error', message: requiredResult.error! };
                    }
                }

                // If title is provided (for any type), validate length
                if (titleValue.trim() !== '') {
                    const lengthResult = validateTextLength(titleValue, {
                        min: 1,
                        max: 325,
                        fieldName: 'Title',
                    });
                    if (!lengthResult.isValid) {
                        return {
                            severity: lengthResult.warning ? 'warning' : 'error',
                            message: lengthResult.error || lengthResult.warning!,
                        };
                    }
                }

                // Check uniqueness across all titles
                const uniquenessResult = validateTitleUniqueness(
                    allTitles.map((t) => ({ title: t.title, type: t.titleType })),
                );
                if (!uniquenessResult.isValid && uniquenessResult.errors[index]) {
                    return {
                        severity: 'error',
                        message: uniquenessResult.errors[index],
                    };
                }

                return null;
            },
        },
    ];

    // License validation rules (primary license is required)
    const primaryLicenseValidationRules: ValidationRule[] = [
        {
            validate: (value) => {
                const result = validateRequired(String(value || ''), 'Primary license');
                if (!result.isValid) {
                    return { severity: 'error', message: result.error! };
                }
                return null;
            },
        },
    ];

    // Abstract (Description) validation rules
    const abstractValidationRules: ValidationRule[] = [
        {
            validate: (value) => {
                const text = String(value || '');
                
                // Required check
                const requiredResult = validateRequired(text, 'Abstract');
                if (!requiredResult.isValid) {
                    return { severity: 'error', message: requiredResult.error! };
                }
                
                // Length check (50-17500 characters)
                const lengthResult = validateTextLength(text, {
                    min: 50,
                    max: 17500,
                    fieldName: 'Abstract'
                });
                if (!lengthResult.isValid) {
                    return { severity: 'error', message: lengthResult.error! };
                }
                
                // Warning at 90% of max length
                if (text.length > 15750) { // 90% of 17500
                    return { 
                        severity: 'warning', 
                        message: `Abstract is very long (${text.length}/17500 characters). Consider condensing if possible.` 
                    };
                }
                
                return null;
            },
        },
    ];

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
    // Also notify user with toast, scroll to section, and switch to MSL tab
    useEffect(() => {
        if (shouldShowMSLSection && !openAccordionItems.includes('msl-laboratories')) {
            setOpenAccordionItems((prev) => [...prev, 'msl-laboratories']);
            
            // Only notify if this is NOT an initial data load and we haven't notified yet
            if (!hasInitialMslTriggers.current && !hasNotifiedMslUnlock.current) {
                hasNotifiedMslUnlock.current = true;
                
                // Show toast notification
                toast.info('MSL Vocabulary Available', {
                    description: 'EPOS/MSL keywords detected. The MSL Vocabulary tab is now available in Controlled Vocabularies.',
                    duration: 5000,
                });
                
                // Trigger auto-switch to MSL tab
                setShouldAutoSwitchToMsl(true);
                
                // Handle scroll and tab-switch with promise chain for better testability
                const scrollAndSwitchTab = async () => {
                    // Wait for accordion animation
                    await new Promise<void>(resolve => setTimeout(resolve, 300));
                    
                    if (!openAccordionItems.includes('controlled-vocabularies')) {
                        // Open the controlled vocabularies accordion first
                        setOpenAccordionItems((prev) => [...prev, 'controlled-vocabularies']);
                    }
                    
                    // Scroll to the section
                    controlledVocabulariesRef.current?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start',
                    });
                    
                    // Wait for scroll animation to complete
                    await new Promise<void>(resolve => setTimeout(resolve, 500));
                    
                    // Reset auto-switch flag after animation completes
                    setShouldAutoSwitchToMsl(false);
                };
                
                void scrollAndSwitchTab();
            }
        } else if (!shouldShowMSLSection && openAccordionItems.includes('msl-laboratories')) {
            setOpenAccordionItems((prev) => prev.filter((item) => item !== 'msl-laboratories'));
            // Reset notification flag when MSL section is hidden
            hasNotifiedMslUnlock.current = false;
        }
    }, [shouldShowMSLSection, openAccordionItems, setShouldAutoSwitchToMsl]);

    // MSL validation info - show recommendation when section is visible but no laboratories selected
    const mslValidationInfo = useMemo(() => {
        if (!shouldShowMSLSection) {
            return null; // Section not visible, no validation needed
        }

        if (mslLaboratories.length === 0) {
            return {
                severity: 'info' as const,
                message: 'This dataset is tagged with EPOS/MSL keywords. Consider adding originating multi-scale laboratories to improve discoverability.',
            };
        }

        return null; // Laboratories are selected, all good
    }, [shouldShowMSLSection, mslLaboratories.length]);
    
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

    // Compute author validation issues
    const authorValidationIssues = useMemo(() => {
        const issues: string[] = [];
        
        if (authors.length === 0) {
            issues.push('At least one author is required');
        } else {
            authors.forEach((author, index) => {
                if (author.type === 'person') {
                    if (!author.lastName.trim()) {
                        issues.push(`Author ${index + 1}: Last name is required`);
                    }
                    if (author.isContact && !author.email.trim()) {
                        issues.push(`Author ${index + 1}: Email is required for contact person`);
                    }
                } else {
                    if (!author.institutionName.trim()) {
                        issues.push(`Author ${index + 1}: Institution name is required`);
                    }
                }
            });
        }
        
        return issues;
    }, [authors]);

    // Date validation issues (general validation for user-entered dates)
    // Note: 'Created' and 'Updated' dates are now auto-managed by the backend
    const dateValidationIssues = useMemo(() => {
        const issues: string[] = [];
        
        // Validate each user-entered date
        dates.forEach((date, index) => {
            const dateIndex = index + 1;
            
            // Validate start date if provided
            if (date.startDate && date.startDate.trim() !== '') {
                const startDateValidation = validateDate(date.startDate, {
                    allowFuture: false,
                    minDate: new Date('1900-01-01'),
                });
                
                if (!startDateValidation.isValid) {
                    issues.push(`Date ${dateIndex} (Start): ${startDateValidation.error}`);
                }
            }
            
            // Validate end date if provided
            if (date.endDate && date.endDate.trim() !== '') {
                const endDateValidation = validateDate(date.endDate, {
                    allowFuture: false,
                    minDate: new Date('1900-01-01'),
                });
                
                if (!endDateValidation.isValid) {
                    issues.push(`Date ${dateIndex} (End): ${endDateValidation.error}`);
                }
            }
            
            // Validate that end date is after start date (if both provided)
            if (date.startDate && date.endDate && 
                date.startDate.trim() !== '' && date.endDate.trim() !== '') {
                const start = new Date(date.startDate);
                const end = new Date(date.endDate);
                
                if (!isNaN(start.getTime()) && !isNaN(end.getTime()) && end < start) {
                    issues.push(`Date ${dateIndex}: End date must be after start date`);
                }
            }
        });
        
        return issues;
    }, [dates]);

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
        // Note: 'Created' date is no longer required from user - it's auto-managed by the backend

        return (
            mainTitleFilled &&
            yearFilled &&
            resourceTypeSelected &&
            languageSelected &&
            primaryLicenseFilled &&
            authorsValid &&
            abstractFilled
        );
    }, [authors, descriptions, form.language, form.resourceType, form.year, licenseEntries, titles]);

    // Check if there are any legacy MSL keywords that need to be replaced
    const hasLegacyKeywords = useMemo(() => {
        return gcmdKeywords.some(kw => kw.isLegacy === true);
    }, [gcmdKeywords]);

    // Collect all missing required fields for Save button tooltip
    const missingRequiredFields = useMemo(() => {
        const missing: string[] = [];

        // Check main title
        const mainTitleEntry = titles.find((entry) => entry.titleType === 'main-title');
        if (!mainTitleEntry?.title.trim()) {
            missing.push('Main Title is required');
        }

        // Check year
        if (!form.year?.trim()) {
            missing.push('Publication Year is required');
        }

        // Check resource type
        if (!form.resourceType) {
            missing.push('Resource Type is required');
        }

        // Check language
        if (!form.language) {
            missing.push('Language is required');
        }

        // Check primary license
        if (!licenseEntries[0]?.license?.trim()) {
            missing.push('Primary License is required');
        }

        // Check authors
        if (authors.length === 0) {
            missing.push('At least one Author is required');
        } else {
            const invalidAuthors = authors.filter((author) => {
                if (author.type === 'person') {
                    const hasLastName = Boolean(author.lastName.trim());
                    const contactValid = !author.isContact || Boolean(author.email.trim());
                    return !hasLastName || !contactValid;
                }
                return !author.institutionName.trim();
            });

            if (invalidAuthors.length > 0) {
                missing.push(`${invalidAuthors.length} Author(s) with missing required fields`);
            }
        }

        // Check abstract
        const abstractEntry = descriptions.find((desc) => desc.type === 'Abstract');
        if (!abstractEntry?.value.trim()) {
            missing.push('Abstract is required');
        } else if (abstractEntry.value.trim().length < 50) {
            missing.push('Abstract must be at least 50 characters');
        }

        // Note: 'Created' date is no longer required from user - it's auto-managed by the backend

        return missing;
    }, [authors, descriptions, form.language, form.resourceType, form.year, licenseEntries, titles]);

    // ===================================================================
    // Accordion Section Status Badges
    // ===================================================================
    // Calculate validation status for each accordion section to show badges:
    // - 'valid' (green check): All required fields complete and valid
    // - 'invalid' (yellow warning): Missing required fields or validation errors
    // - 'optional-empty' (gray circle): Optional section with no content

    const resourceInfoStatus = useMemo(() => {
        const mainTitleEntry = titles.find((entry) => entry.titleType === 'main-title');
        const hasMainTitle = Boolean(mainTitleEntry?.title.trim());
        const hasYear = Boolean(form.year?.trim());
        const hasResourceType = Boolean(form.resourceType);
        const hasLanguage = Boolean(form.language);

        // Check if DOI has validation errors (if present)
        const doiMessages = getFieldState('doi').messages;
        const hasDoiError = doiMessages.some((msg) => msg.severity === 'error');

        // Check if Year has validation errors (if present)
        const yearMessages = getFieldState('year').messages;
        const hasYearError = yearMessages.some((msg) => msg.severity === 'error');

        // Check if Version has validation errors (if present)
        const versionMessages = getFieldState('version').messages;
        const hasVersionError = versionMessages.some((msg) => msg.severity === 'error');

        const allRequiredPresent = hasMainTitle && hasYear && hasResourceType && hasLanguage;
        const hasErrors = hasDoiError || hasYearError || hasVersionError;

        if (!allRequiredPresent || hasErrors) {
            return 'invalid';
        }
        return 'valid';
    }, [titles, form.year, form.resourceType, form.language, getFieldState]);

    const licensesStatus = useMemo(() => {
        const primaryLicense = licenseEntries[0]?.license?.trim();
        if (!primaryLicense) {
            return 'invalid';
        }
        return 'valid';
    }, [licenseEntries]);

    const authorsStatus = useMemo(() => {
        if (authors.length === 0) {
            return 'invalid';
        }
        if (authorValidationIssues.length > 0) {
            return 'invalid';
        }
        return 'valid';
    }, [authors.length, authorValidationIssues.length]);

    const contributorsStatus = useMemo(() => {
        // Contributors are optional
        const hasAnyContributor = contributors.some((contributor) => {
            if (contributor.type === 'person') {
                return contributor.lastName.trim() !== '';
            }
            return contributor.institutionName.trim() !== '';
        });

        if (!hasAnyContributor) {
            return 'optional-empty';
        }

        // If present, check for validation issues
        // (Currently no specific contributor validation, but could be added)
        return 'valid';
    }, [contributors]);

    const descriptionsStatus = useMemo(() => {
        const abstractEntry = descriptions.find((desc) => desc.type === 'Abstract');
        if (!abstractEntry?.value.trim()) {
            return 'invalid';
        }
        if (abstractEntry.value.trim().length < 50) {
            return 'invalid';
        }

        // Check for validation errors
        const abstractMessages = getFieldState('abstract').messages;
        const hasAbstractError = abstractMessages.some((msg) => msg.severity === 'error');
        if (hasAbstractError) {
            return 'invalid';
        }

        return 'valid';
    }, [descriptions, getFieldState]);

    const controlledVocabulariesStatus = useMemo(() => {
        // Controlled vocabularies are optional
        if (gcmdKeywords.length === 0) {
            return 'optional-empty';
        }
        return 'valid';
    }, [gcmdKeywords.length]);

    const freeKeywordsStatus = useMemo(() => {
        // Free keywords are optional
        const hasKeywords = freeKeywords.some((kw) => kw.value.trim() !== '');
        if (!hasKeywords) {
            return 'optional-empty';
        }
        return 'valid';
    }, [freeKeywords]);

    const mslLaboratoriesStatus = useMemo(() => {
        // MSL section only relevant if EPOS/MSL keywords present
        if (!shouldShowMSLSection) {
            return 'optional-empty'; // Section hidden, not relevant
        }

        if (mslLaboratories.length === 0) {
            return 'invalid'; // Show info message (recommendation)
        }

        return 'valid';
    }, [shouldShowMSLSection, mslLaboratories.length]);

    const spatialTemporalCoverageStatus = useMemo(() => {
        // Spatial/temporal coverage is optional
        const hasAnyCoverage = spatialTemporalCoverages.some(
            (coverage) =>
                coverage.latMin.trim() !== '' ||
                coverage.lonMin.trim() !== '' ||
                coverage.startDate.trim() !== ''
        );

        if (!hasAnyCoverage) {
            return 'optional-empty';
        }

        return 'valid';
    }, [spatialTemporalCoverages]);

    const datesStatus = useMemo(() => {
        // Dates section is now optional since 'Created' and 'Updated' are auto-managed
        const hasAnyDate = dates.some((date) => hasValidDateValue(date));
        
        if (!hasAnyDate) {
            return 'optional-empty';
        }
        
        if (dateValidationIssues.length > 0) {
            return 'invalid';
        }
        return 'valid';
    }, [dates, dateValidationIssues.length]);

    const relatedWorkStatus = useMemo(() => {
        // Related work is optional
        const hasAnyRelatedWork = relatedWorks.some((rw) => rw.identifier.trim() !== '');
        if (!hasAnyRelatedWork) {
            return 'optional-empty';
        }
        return 'valid';
    }, [relatedWorks]);

    const fundingReferencesStatus = useMemo(() => {
        // Funding references are optional
        const hasAnyFunding = fundingReferences.some((fr) => fr.funderName.trim() !== '');
        if (!hasAnyFunding) {
            return 'optional-empty';
        }

        // Check for validation errors
        const hasErrors = !validateAllFundingReferences(fundingReferences);
        if (hasErrors) {
            return 'invalid';
        }

        return 'valid';
    }, [fundingReferences]);

    // ===================================================================
    // Auto-Scroll to First Invalid Section
    // ===================================================================
    // Scrolls to the first accordion section with validation errors
    // Opens the section automatically and focuses the first problematic field
    const scrollToFirstInvalidSection = () => {
        // Define priority order of sections to check
        // Note: Dates section is excluded as it's now optional (Created/Updated are auto-managed)
        const sectionsToCheck: Array<{
            status: 'valid' | 'invalid' | 'optional-empty';
            ref: React.RefObject<HTMLDivElement | null>;
            accordionValue: string;
            focusSelector?: string; // CSS selector for first field to focus
        }> = [
            {
                status: resourceInfoStatus,
                ref: resourceInfoRef,
                accordionValue: 'resource-info',
                focusSelector: '#main-title-input', // Focus main title if invalid
            },
            {
                status: licensesStatus,
                ref: licensesRef,
                accordionValue: 'licenses-rights',
                focusSelector: '[data-testid="license-select-0"]', // Focus primary license
            },
            {
                status: authorsStatus,
                ref: authorsRef,
                accordionValue: 'authors',
                // Authors is complex, just scroll to section
            },
            {
                status: descriptionsStatus,
                ref: descriptionsRef,
                accordionValue: 'descriptions',
                focusSelector: '[data-testid="abstract-textarea"]', // Focus abstract
            },
        ];

        // Find first invalid section
        const firstInvalidSection = sectionsToCheck.find((section) => section.status === 'invalid');

        if (firstInvalidSection) {
            // Open the accordion section
            setOpenAccordionItems((prev) => {
                if (!prev.includes(firstInvalidSection.accordionValue)) {
                    return [...prev, firstInvalidSection.accordionValue];
                }
                return prev;
            });

            // Scroll to the section after a brief delay to allow accordion to open
            setTimeout(() => {
                if (firstInvalidSection.ref.current) {
                    firstInvalidSection.ref.current.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start',
                    });

                    // Focus the first field if selector provided
                    if (firstInvalidSection.focusSelector) {
                        setTimeout(() => {
                            const fieldToFocus = document.querySelector(
                                firstInvalidSection.focusSelector!
                            ) as HTMLElement;
                            if (fieldToFocus) {
                                fieldToFocus.focus();
                            }
                        }, 400); // Additional delay for smooth scroll to complete
                    }
                }
            }, 100); // Brief delay for accordion animation
        }
    };

    const handleChange = (field: keyof DataCiteFormData, value: string) => {
        setForm((prev) => ({ ...prev, [field]: value }));

        // Trigger validation based on field
        switch (field) {
            case 'doi':
                validateField({
                    fieldId: 'doi',
                    value,
                    rules: doiValidationRules,
                    formData: form,
                });
                break;
            case 'year':
                validateField({
                    fieldId: 'year',
                    value,
                    rules: yearValidationRules,
                    formData: form,
                });
                break;
            case 'resourceType':
                validateField({
                    fieldId: 'resourceType',
                    value,
                    rules: resourceTypeValidationRules,
                    formData: form,
                });
                break;
            case 'language':
                validateField({
                    fieldId: 'language',
                    value,
                    rules: languageValidationRules,
                    formData: form,
                });
                break;
            case 'version':
                validateField({
                    fieldId: 'version',
                    value,
                    rules: versionValidationRules,
                    formData: form,
                });
                break;
        }
    };

    const handleTitleChange = (
        index: number,
        field: keyof Omit<TitleEntry, 'id'>,
        value: string,
    ) => {
        setTitles((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], [field]: value };

            // Validate title text when it changes
            if (field === 'title') {
                const titleType = next[index].titleType;
                validateField({
                    fieldId: `title-${index}`,
                    value,
                    rules: createTitleValidationRules(index, titleType, next),
                    formData: form,
                });

                // Revalidate all other titles for uniqueness
                next.forEach((t, idx) => {
                    if (idx !== index && t.title.trim() !== '') {
                        validateField({
                            fieldId: `title-${idx}`,
                            value: t.title,
                            rules: createTitleValidationRules(idx, t.titleType, next),
                            formData: form,
                        });
                    }
                });
            }

            // When title type changes, revalidate the title text
            if (field === 'titleType') {
                const titleText = next[index].title;
                validateField({
                    fieldId: `title-${index}`,
                    value: titleText,
                    rules: createTitleValidationRules(index, value, next),
                    formData: form,
                });
            }

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

    const handleDescriptionChange = (descriptions: DescriptionEntry[]) => {
        setDescriptions(descriptions);

        // Validate Abstract field if it exists
        const abstractEntry = descriptions.find((d) => d.type === 'Abstract');
        if (abstractEntry !== undefined) {
            validateField({
                fieldId: 'abstract',
                value: abstractEntry.value,
                rules: abstractValidationRules,
                formData: form,
            });
        }
    };

    const handleLicenseChange = (index: number, value: string) => {
        setLicenseEntries((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], license: value };

            // Validate primary license (index 0)
            if (index === 0) {
                validateField({
                    fieldId: 'license-0',
                    value,
                    rules: primaryLicenseValidationRules,
                    formData: form,
                });
            }

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
        const availableType = dateTypeOptions.find((dt) => !usedTypes.has(dt.value))?.value ?? 'other';
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

        // Check if required fields are filled - if not, scroll to first invalid section
        if (!areRequiredFieldsFilled) {
            setIsSaving(false);
            scrollToFirstInvalidSection();
            return;
        }

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

    // ===================================================================
    // Status Badge Rendering Helper
    // ===================================================================
    const renderStatusBadge = (status: 'valid' | 'invalid' | 'optional-empty') => {
        if (status === 'valid') {
            return <CheckCircle className="h-4 w-4 text-green-600" aria-label="Section complete" />;
        }
        if (status === 'invalid') {
            return <AlertCircle className="h-4 w-4 text-yellow-600" aria-label="Section incomplete or has errors" />;
        }
        return <Circle className="h-4 w-4 text-gray-400" aria-label="Optional section" />;
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
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Resource Information</span>
                            {renderStatusBadge(resourceInfoStatus)}
                        </div>
                    </AccordionTrigger>
                    <AccordionContent ref={resourceInfoRef} className="space-y-6">
                        <div className="grid gap-4 md:grid-cols-12">
                            <InputField
                                id="doi"
                                label="DOI"
                                value={form.doi || ''}
                                onChange={(e) => handleChange('doi', e.target.value)}
                                onValidationBlur={() => markFieldTouched('doi')}
                                validationMessages={getFieldState('doi').messages}
                                touched={getFieldState('doi').touched}
                                placeholder="10.xxxx/xxxxx"
                                labelTooltip="Enter DOI in format 10.xxxx/xxxxx or https://doi.org/10.xxxx/xxxxx"
                                className="md:col-span-3"
                            />
                            <InputField
                                id="year"
                                type="number"
                                label="Year"
                                value={form.year || ''}
                                onChange={(e) => handleChange('year', e.target.value)}
                                onValidationBlur={() => handleFieldBlur('year', form.year, yearValidationRules)}
                                validationMessages={getFieldState('year').messages}
                                touched={getFieldState('year').touched}
                                placeholder="2024"
                                className="md:col-span-2"
                                required
                            />
                            <SelectField
                                id="resourceType"
                                label="Resource Type"
                                value={form.resourceType || ''}
                                onValueChange={(val) => handleChange('resourceType', val)}
                                onValidationBlur={() => markFieldTouched('resourceType')}
                                validationMessages={getFieldState('resourceType').messages}
                                touched={getFieldState('resourceType').touched}
                                options={resourceTypes.map((type) => ({
                                    value: String(type.id),
                                    label: type.name,
                                }))}
                                className="md:col-span-4"
                                required
                                data-testid="resource-type-select"
                            />
                            <InputField
                                id="version"
                                label="Version"
                                value={form.version || ''}
                                onChange={(e) => handleChange('version', e.target.value)}
                                onValidationBlur={() => markFieldTouched('version')}
                                validationMessages={getFieldState('version').messages}
                                touched={getFieldState('version').touched}
                                placeholder="1.0"
                                labelTooltip="Semantic versioning (e.g., 1.2.3)"
                                className="md:col-span-1"
                            />
                            <SelectField
                                id="language"
                                label="Language of Data"
                                value={form.language || ''}
                                onValueChange={(val) => handleChange('language', val)}
                                onValidationBlur={() => markFieldTouched('language')}
                                validationMessages={getFieldState('language').messages}
                                touched={getFieldState('language').touched}
                                options={languages.map((l) => ({
                                    value: l.code,
                                    label: l.name,
                                }))}
                                className="md:col-span-2"
                                required
                                data-testid="language-select"
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
                                    validationMessages={getFieldState(`title-${index}`).messages}
                                    touched={getFieldState(`title-${index}`).touched}
                                    onValidationBlur={() => handleFieldBlur(`title-${index}`, entry.title, createTitleValidationRules(index, entry.titleType, titles))}
                                />
                            ))}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="licenses-rights">
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Licenses and Rights</span>
                            {renderStatusBadge(licensesStatus)}
                        </div>
                    </AccordionTrigger>
                    <AccordionContent ref={licensesRef}>
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
                                    validationMessages={index === 0 ? getFieldState('license-0').messages : undefined}
                                    touched={index === 0 ? getFieldState('license-0').touched : undefined}
                                    onValidationBlur={index === 0 ? () => markFieldTouched('license-0') : undefined}
                                    data-testid={`license-select-${index}`}
                                />
                            ))}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="authors">
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Authors</span>
                            {renderStatusBadge(authorsStatus)}
                        </div>
                    </AccordionTrigger>
                    <AccordionContent ref={authorsRef}>
                        {/* Validation issues notification */}
                        {authorValidationIssues.length > 0 && (
                            <div
                                className="mb-4 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
                                role="alert"
                                aria-live="polite"
                            >
                                <strong>Required fields missing:</strong>
                                <ul className="mt-2 list-disc pl-5 space-y-1">
                                    {authorValidationIssues.map((issue, idx) => (
                                        <li key={idx}>{issue}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
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
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Contributors</span>
                            {renderStatusBadge(contributorsStatus)}
                        </div>
                    </AccordionTrigger>
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
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Descriptions</span>
                            {renderStatusBadge(descriptionsStatus)}
                        </div>
                    </AccordionTrigger>
                    <AccordionContent ref={descriptionsRef}>
                        <DescriptionField
                            descriptions={descriptions}
                            onChange={handleDescriptionChange}
                            abstractValidationMessages={getFieldMessages('abstract')}
                            abstractTouched={getFieldState('abstract').touched}
                            onAbstractValidationBlur={() => markFieldTouched('abstract')}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="controlled-vocabularies" ref={controlledVocabulariesRef}>
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Controlled Vocabularies</span>
                            {renderStatusBadge(controlledVocabulariesStatus)}
                        </div>
                    </AccordionTrigger>
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
                                autoSwitchToMsl={shouldAutoSwitchToMsl}
                            />
                        )}
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="free-keywords">
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Free Keywords</span>
                            {renderStatusBadge(freeKeywordsStatus)}
                        </div>
                    </AccordionTrigger>
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
                                {renderStatusBadge(mslLaboratoriesStatus)}
                            </div>
                        </AccordionTrigger>
                        <AccordionContent>
                            {mslValidationInfo && (
                                <div
                                    className="mb-4 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900"
                                    role="status"
                                    aria-live="polite"
                                >
                                    <div className="flex items-start gap-2">
                                        <svg
                                            className="mt-0.5 h-4 w-4 shrink-0"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                strokeWidth={2}
                                                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                            />
                                        </svg>
                                        <div>
                                            <strong className="font-semibold">Recommendation:</strong>
                                            <p className="mt-1">{mslValidationInfo.message}</p>
                                        </div>
                                    </div>
                                </div>
                            )}
                            <MSLLaboratoriesField
                                selectedLaboratories={mslLaboratories}
                                onChange={setMslLaboratories}
                            />
                        </AccordionContent>
                    </AccordionItem>
                )}
                <AccordionItem value="spatial-temporal-coverage">
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Spatial and Temporal Coverage</span>
                            {renderStatusBadge(spatialTemporalCoverageStatus)}
                        </div>
                    </AccordionTrigger>
                    <AccordionContent>
                        <SpatialTemporalCoverageField
                            coverages={spatialTemporalCoverages}
                            apiKey={googleMapsApiKey}
                            onChange={setSpatialTemporalCoverages}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="dates">
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Dates</span>
                            {renderStatusBadge(datesStatus)}
                        </div>
                    </AccordionTrigger>
                    <AccordionContent ref={datesRef}>
                        {dateValidationIssues.length > 0 && (
                            <div
                                className="mb-4 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
                                role="alert"
                                aria-live="polite"
                            >
                                <strong>Date validation issues:</strong>
                                <ul className="mt-2 list-disc pl-5 space-y-1">
                                    {dateValidationIssues.map((issue, idx) => (
                                        <li key={idx}>{issue}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <div className="space-y-4">
                            {dates.length === 0 ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addDate}
                                    aria-label="Add date"
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add date
                                </Button>
                            ) : (
                                dates.map((entry, index) => {
                                    const selectedDateType = dateTypeOptions.find(dt => dt.value === entry.dateType);
                                    return (
                                        <DateField
                                            key={entry.id}
                                            id={entry.id}
                                            startDate={entry.startDate}
                                            endDate={entry.endDate}
                                            dateType={entry.dateType}
                                            dateTypeDescription={selectedDateType?.description}
                                            options={dateTypeOptions.filter(
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
                                })
                            )}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="related-work">
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Related Work</span>
                            {renderStatusBadge(relatedWorkStatus)}
                        </div>
                    </AccordionTrigger>
                    <AccordionContent>
                        <RelatedWorkField
                            relatedWorks={relatedWorks}
                            onChange={setRelatedWorks}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="funding-references">
                    <AccordionTrigger>
                        <div className="flex items-center gap-2">
                            <span>Funding References</span>
                            {renderStatusBadge(fundingReferencesStatus)}
                        </div>
                    </AccordionTrigger>
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
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span tabIndex={0}>
                                <Button
                                    type="submit"
                                    disabled={isSaving || !areRequiredFieldsFilled || hasLegacyKeywords}
                                    aria-busy={isSaving}
                                    aria-disabled={isSaving || !areRequiredFieldsFilled || hasLegacyKeywords}
                                >
                                    {isSaving ? 'Saving…' : 'Save to database'}
                                </Button>
                            </span>
                        </TooltipTrigger>
                        {(!areRequiredFieldsFilled || hasLegacyKeywords) && !isSaving && (
                            <TooltipContent
                                side="top"
                                align="end"
                                className="max-w-sm"
                            >
                                <div className="space-y-2">
                                    <p className="font-semibold text-sm">
                                        {hasLegacyKeywords
                                            ? 'Cannot save: Legacy keywords detected'
                                            : 'Cannot save: Required fields missing'}
                                    </p>
                                    {hasLegacyKeywords ? (
                                        <p className="text-xs">
                                            Please replace all legacy MSL keywords with keywords from the current vocabulary.
                                        </p>
                                    ) : (
                                        <>
                                            <p className="text-xs text-muted-foreground">
                                                Please complete the following required fields:
                                            </p>
                                            <ul className="text-xs space-y-1 list-disc pl-4">
                                                {missingRequiredFields.map((field, idx) => (
                                                    <li key={idx}>{field}</li>
                                                ))}
                                            </ul>
                                        </>
                                    )}
                                </div>
                            </TooltipContent>
                        )}
                    </Tooltip>
                </TooltipProvider>
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

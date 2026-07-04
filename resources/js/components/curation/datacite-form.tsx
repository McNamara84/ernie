import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, Calendar, CheckCircle, ChevronsDown, ChevronsUp, Circle, Eye, Save } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { ClickableValidationAlert } from '@/components/curation/clickable-validation-alert';
import { DoiConflictModal } from '@/components/curation/modals/doi-conflict-modal';
import {
    RELATED_ITEMS_SECTION_DESCRIPTION,
    RELATED_ITEMS_SECTION_HELP,
    RELATED_ITEMS_SECTION_LABEL,
} from '@/components/curation/related-items-section-copy';
import { AccordionSectionHeader, SectionHelpAction } from '@/components/curation/section-header';
import { mapBackendErrors, type MappedError } from '@/components/curation/utils/error-field-mapper';
import { scheduleScrollToError } from '@/components/curation/utils/scroll-to-error';
import { LANDING_PAGE_POPUP_BLOCKED_MESSAGE, openLandingPagePreviewPlaceholder } from '@/components/landing-pages/landing-page-preview-window';
import SetupLandingPageModal from '@/components/landing-pages/modals/SetupLandingPageModal';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { ValidationAlert } from '@/components/ui/validation-alert';
import { useDoiValidation } from '@/hooks/use-doi-validation';
import { useFormValidation, type ValidationRule } from '@/hooks/use-form-validation';
import { validateAllFundingReferences } from '@/hooks/use-funding-reference-validation';
import { useRorAffiliations } from '@/hooks/use-ror-affiliations';
import { CURATION_ACCORDION_ITEM_VALUES, DEFAULT_OPEN_ACCORDION_ITEMS } from '@/lib/curation-accordion';
import { buildDateTime, hasValidDateValue, parseDateTime } from '@/lib/date-utils';
import { resources } from '@/routes';
import { store, storeDraft } from '@/routes/editor/resources';
import type { CurationAccordionItemValue, InstrumentSelection, MSLLaboratory, RelatedIdentifier, SharedData } from '@/types';
import type { LandingPageConfig } from '@/types/landing-page';
import type { SelectedKeyword, VocabularyKeyword } from '@/types/vocabulary';
import { getVocabularyTypeFromScheme } from '@/types/vocabulary';
import {
    validateDate,
    validateDOIFormat,
    validateRequired,
    validateTextLength,
    validateTitleUniqueness,
    validateVersion,
    validateYear,
} from '@/utils/validation-rules';

import AuthorField, { type AuthorEntry } from './fields/author';
import { CitationsField } from './fields/citations-field';
import ContributorField, { type ContributorEntry } from './fields/contributor';
import ControlledVocabulariesField from './fields/controlled-vocabularies-field';
import { DatacenterField } from './fields/datacenter-field';
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
import UsedInstrumentsField from './fields/used-instruments-field';
import {
    type CustomLicenseEntry,
    type DataCiteFormData,
    type DataCiteFormProps,
    type DateEntry,
    type EditorLandingPageSummary,
    type LicenseEntry,
    MAIN_TITLE_SLUG,
    type RawRightsInput,
    type SerializedAuthor,
    type SerializedContributor,
    type TitleEntry,
} from './types/datacite-form-types';
import { type DateMode, isDateRangeCapable, isEditableDateType, normalizeDateTypeSlug } from './utils/date-rules';
import {
    canAddDate,
    canAddTitle,
    hasAnyLicenseEntryContent,
    hasCompleteLicenseEntry,
    isHttpUrl,
    mapInitialAuthorToEntry,
    mapInitialContributorToEntry,
    normalizeTitleTypeSlug,
    serializeAffiliations,
} from './utils/form-helpers';
import { resolveInitialLanguageCode } from './utils/language-resolver';

// Re-export types for backward compatibility with existing imports
export type { DataCiteFormProps, EditorLandingPageSummary, InitialAuthor, InitialContributor, RawRightsInput } from './types/datacite-form-types';

// Re-export helper functions for backward compatibility
export { canAddDate, canAddLicense, canAddTitle } from './utils/form-helpers';

const ABSTRACT_MIN_LENGTH = 50;
const ABSTRACT_MAX_LENGTH = 17500;
const CURATION_ACCORDION_PREFERENCE_URL = '/settings/curation-accordion';
const SECTION_TRIGGER_CLASS_NAME = 'hover:no-underline';
const DRAFT_AUTOSAVE_INTERVAL_MS = 60_000;

type DraftAutosaveStatus = 'idle' | 'saving' | 'saved' | 'error';

type DraftSaveResponse = {
    message?: string;
    resource?: { id: number };
};

type LandingPagePreviewTarget = {
    status?: LandingPageConfig['status'];
    public_url?: string | null;
    preview_url?: string | null;
    external_url?: string | null;
};

type LandingPagePreviewSetupResource = {
    id: number;
    doi?: string | null;
    title?: string;
    resourcetypegeneral?: string;
};

function normalizeLandingPagePreviewTarget(url?: string | null): string | null {
    const trimmedUrl = url?.trim();

    return trimmedUrl || null;
}

function getLandingPagePreviewTarget(landingPage: LandingPagePreviewTarget): string | null {
    if (landingPage.status === 'draft') {
        return normalizeLandingPagePreviewTarget(landingPage.preview_url);
    }

    return normalizeLandingPagePreviewTarget(landingPage.public_url) ?? normalizeLandingPagePreviewTarget(landingPage.external_url);
}

function getLandingPagePreviewMissingUrlMessage(landingPage: LandingPagePreviewTarget): string {
    if (landingPage.status === 'draft') {
        return 'Unable to open landing page preview. The preview URL is missing.';
    }

    return 'Unable to open landing page. The public or external URL is missing.';
}

function toEditorLandingPageSummary(landingPage: LandingPageConfig): EditorLandingPageSummary {
    return {
        id: landingPage.id,
        is_published: landingPage.status === 'published',
        status: landingPage.status,
        public_url: landingPage.public_url,
        preview_url: landingPage.preview_url,
        external_url: landingPage.external_url,
    };
}

function normalizeAccordionItems(
    items: readonly string[],
    allowedItems: readonly CurationAccordionItemValue[] = CURATION_ACCORDION_ITEM_VALUES,
): CurationAccordionItemValue[] {
    const allowed = new Set<CurationAccordionItemValue>(allowedItems);
    const result: CurationAccordionItemValue[] = [];

    for (const item of items) {
        if (!allowed.has(item as CurationAccordionItemValue) || result.includes(item as CurationAccordionItemValue)) {
            continue;
        }

        result.push(item as CurationAccordionItemValue);
    }

    return result;
}

function appendValidationMessage(errors: Record<string, string[]>, backendKey: string, message: string): void {
    const existing = errors[backendKey];

    if (existing) {
        existing.push(message);
        return;
    }

    errors[backendKey] = [message];
}

function isRawRightsOnlyLicenseEntry(entry: LicenseEntry): entry is CustomLicenseEntry {
    return entry.mode === 'custom' && entry.rawRight !== undefined && entry.uri.trim() === '' && entry.name.trim() !== '';
}

function isCustomLicensePayloadEntry(entry: LicenseEntry): entry is CustomLicenseEntry {
    return entry.mode === 'custom' && hasAnyLicenseEntryContent(entry) && !isRawRightsOnlyLicenseEntry(entry);
}

function isCatalogLicensePayloadEntry(entry: LicenseEntry): entry is Extract<LicenseEntry, { mode: 'catalog' }> {
    return entry.mode === 'catalog' && entry.license.trim() !== '';
}

function hasLicenseEntryEvidence(entry: LicenseEntry | undefined): boolean {
    return hasCompleteLicenseEntry(entry) || (entry !== undefined && isRawRightsOnlyLicenseEntry(entry));
}

function canAddLicenseEntry(licenseEntries: LicenseEntry[], maxLicenses: number): boolean {
    return licenseEntries.length < maxLicenses && licenseEntries.length > 0 && hasLicenseEntryEvidence(licenseEntries[licenseEntries.length - 1]);
}

function serializeRawRightsOnlyLicenseEntry(entry: CustomLicenseEntry): RawRightsInput {
    return {
        ...entry.rawRight,
        rights: entry.name.trim(),
        rightsUri: null,
        sourceResourceRightId: entry.sourceResourceRightId ?? entry.rawRight?.sourceResourceRightId ?? null,
    };
}

function isDatacenterErrorKey(backendKey: string): boolean {
    return backendKey === 'datacenters' || backendKey.startsWith('datacenters.');
}

function isDatacenterElementErrorKey(backendKey: string): boolean {
    return backendKey.startsWith('datacenters.');
}

export default function DataCiteForm({
    resourceTypes,
    titleTypes,
    dateTypes,
    descriptionTypes,
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
    initialRawRights = [],
    initialResourceId,
    initialLandingPage = null,
    initialAuthors = [],
    initialContributors = [],
    initialDescriptions = [],
    initialDates = [],
    initialGcmdKeywords = [],
    initialFreeKeywords = [],
    initialGemetKeywords = [],
    initialSpatialTemporalCoverages = [],
    initialMslLaboratories = [],
    initialInstruments = [],
    initialRelatedWorks = [],
    initialRelatedItems = [],
    initialFundingReferences = [],
    initialDatacenters = [],
    availableDatacenters = [],
    isUserAdmin,
    activeRelationTypes,
    activeIdentifierTypes,
}: DataCiteFormProps) {
    const { curationAccordionOpenItems } = usePage<SharedData>().props;
    const MAX_TITLES = maxTitles;
    const MAX_LICENSES = maxLicenses;

    // Date types shown in the Dates section. Accepted/Issued/Updated are system-managed;
    // Coverage is edited exclusively in Spatial and Temporal Coverage.
    const MAX_DATES = dateTypes.filter((dt) => isEditableDateType(dt.slug)).length;

    const dateTypeOptions = useMemo(
        () =>
            dateTypes
                .filter((dt) => isEditableDateType(dt.slug))
                .map((dt) => ({
                    value: dt.slug,
                    label: dt.name,
                    description: dt.description ?? '',
                })),
        [dateTypes],
    );

    const errorRef = useRef<HTMLDivElement | null>(null);
    const controlledVocabulariesRef = useRef<HTMLDivElement | null>(null);

    // Tracking refs for MSL notification
    const hasNotifiedMslUnlock = useRef<boolean>(false);
    const hasInitialMslTriggers = useRef<boolean>(false);
    // Refs to track MSL scroll/animation timeouts for cleanup (separate refs to avoid overwriting)
    const mslScrollTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const mslAnimationTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const accordionPreferenceTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [form, setForm] = useState<DataCiteFormData>({
        doi: initialDoi,
        year: initialYear,
        resourceType: initialResourceType,
        version: initialVersion,
        language: resolveInitialLanguageCode(languages, initialLanguage),
    });

    const [titles, setTitles] = useState<TitleEntry[]>(() => {
        if (!initialTitles.length) {
            return [{ id: crypto.randomUUID(), title: '', titleType: MAIN_TITLE_SLUG }];
        }

        // Ensure we never produce empty titleType strings.
        const defaultSecondaryType = titleTypes.find((t) => t.slug !== MAIN_TITLE_SLUG)?.slug ?? MAIN_TITLE_SLUG;
        let mainTitleAssigned = false;

        return initialTitles.map((t, index) => {
            const normalized = normalizeTitleTypeSlug(t.titleType);
            const wantsMainTitle = normalized === MAIN_TITLE_SLUG || (!normalized && index === 0);

            if (wantsMainTitle && !mainTitleAssigned) {
                mainTitleAssigned = true;
                return {
                    id: crypto.randomUUID(),
                    title: t.title,
                    titleType: MAIN_TITLE_SLUG,
                    language: t.language ?? null,
                };
            }

            return {
                id: crypto.randomUUID(),
                title: t.title,
                // If the first entry had an empty type but a main title is already assigned elsewhere,
                // fall back to a non-main type instead of keeping it empty.
                titleType: normalized || defaultSecondaryType,
                language: t.language ?? null,
            };
        });
    });

    const [licenseEntries, setLicenseEntries] = useState<LicenseEntry[]>(() => {
        const entries: LicenseEntry[] = [];
        const licensesByIdentifier = new Map(licenses.map((license) => [license.identifier, license]));

        initialLicenses.forEach((identifier) => {
            const catalogLicense = licensesByIdentifier.get(identifier);

            if (catalogLicense && (catalogLicense.scheme_uri === null || catalogLicense.identifier.startsWith('CUSTOM-'))) {
                entries.push({
                    id: crypto.randomUUID(),
                    mode: 'custom',
                    name: catalogLicense.name,
                    uri: catalogLicense.uri ?? '',
                });
                return;
            }

            entries.push({
                id: crypto.randomUUID(),
                mode: 'catalog',
                license: identifier,
            });
        });

        initialRawRights.forEach((rawRight) => {
            const name = (rawRight.rights ?? rawRight.rightsIdentifier ?? '').trim();
            const uri = (rawRight.rightsUri ?? '').trim();

            if (!name && !uri) {
                return;
            }

            entries.push({
                id: crypto.randomUUID(),
                mode: 'custom',
                name,
                uri,
                sourceResourceRightId: rawRight.sourceResourceRightId ?? undefined,
                rawRight,
            });
        });

        return entries.length > 0 ? entries : [{ id: crypto.randomUUID(), mode: 'catalog', license: '' }];
    });

    const customLicensePayloadIndexesByEntryId = useMemo(() => {
        const indexes = new Map<string, number>();
        let customLicenseIndex = 0;

        licenseEntries.forEach((entry) => {
            if (!isCustomLicensePayloadEntry(entry)) {
                return;
            }

            indexes.set(entry.id, customLicenseIndex);
            customLicenseIndex += 1;
        });

        return indexes;
    }, [licenseEntries]);

    const [authors, setAuthors] = useState<AuthorEntry[]>(() => {
        if (initialAuthors.length > 0) {
            const mapped = initialAuthors.map((author) => mapInitialAuthorToEntry(author)).filter((author): author is AuthorEntry => Boolean(author));

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
                language: desc.language ?? null,
            }));
        }
        return [];
    });
    const [dates, setDates] = useState<DateEntry[]>(() => {
        if (initialDates && initialDates.length > 0) {
            return initialDates
                .filter((date) => isEditableDateType(date.dateType))
                .map((date) => {
                    // Parse ISO 8601 datetime values to separate date, time, and timezone
                    const parsedStart = parseDateTime(date.startDate);
                    const parsedEnd = parseDateTime(date.endDate);
                    const isRangeCapable = isDateRangeCapable(date.dateType);
                    const dateMode: DateMode =
                        (date.dateMode === 'range' && isRangeCapable) ||
                        (date.dateMode === undefined && isRangeCapable && parsedStart.date !== '' && parsedEnd.date !== '')
                            ? 'range'
                            : 'single';

                    return {
                        id: crypto.randomUUID(),
                        dateType: date.dateType,
                        dateMode,
                        startDate: parsedStart.date || null,
                        endDate: dateMode === 'range' ? parsedEnd.date || null : null,
                        startTime: parsedStart.time,
                        endTime: dateMode === 'range' ? parsedEnd.time : null,
                        startTimezone: parsedStart.timezone,
                        endTimezone: dateMode === 'range' ? parsedEnd.timezone : null,
                    };
                });
        }
        // Start with an empty Dates section when no editable imported dates exist.
        return [];
    });

    const [gcmdKeywords, setGcmdKeywords] = useState<SelectedKeyword[]>(() => {
        const combined = [...(initialGcmdKeywords ?? []), ...(initialGemetKeywords ?? [])];
        if (combined.length > 0) {
            return combined
                .filter((kw): kw is typeof kw & { scheme: string } => typeof kw.scheme === 'string' && kw.scheme.length > 0)
                .map((kw) => ({
                    id: kw.id,
                    text: kw.text,
                    path: kw.path,
                    language: 'language' in kw && typeof kw.language === 'string' ? kw.language : 'en',
                    scheme: kw.scheme,
                    schemeURI: 'schemeURI' in kw && typeof kw.schemeURI === 'string' ? kw.schemeURI : '',
                    classificationCode:
                        'classificationCode' in kw && typeof kw.classificationCode === 'string' && kw.classificationCode.trim() !== ''
                            ? kw.classificationCode.trim()
                            : undefined,
                    isLegacy: 'isLegacy' in kw && (kw.isLegacy === true || kw.isLegacy === 'true' || kw.isLegacy === '1'),
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
        const hasInitialControlledMslKeywords = [...(initialGcmdKeywords ?? []), ...(initialGemetKeywords ?? [])].some(
            (keyword) => typeof keyword.scheme === 'string' && getVocabularyTypeFromScheme(keyword.scheme) === 'msl',
        );

        if (initialFreeKeywords && initialFreeKeywords.length > 0) {
            const triggers = ['epos', 'multi-scale laboratories', 'multi scale laboratories', 'msl'];
            const hasInitialTriggers = initialFreeKeywords.some((keyword) => triggers.some((trigger) => keyword.toLowerCase().includes(trigger)));
            hasInitialMslTriggers.current = hasInitialTriggers || hasInitialControlledMslKeywords;
        } else {
            hasInitialMslTriggers.current = hasInitialControlledMslKeywords;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []); // Run only once on mount - initialFreeKeywords intentionally excluded
    const [spatialTemporalCoverages, setSpatialTemporalCoverages] = useState<SpatialTemporalCoverageEntry[]>(() => {
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
    const [instruments, setInstruments] = useState<InstrumentSelection[]>(() => {
        if (initialInstruments && initialInstruments.length > 0) {
            return initialInstruments;
        }
        return [];
    });
    const [selectedDatacenters, setSelectedDatacenters] = useState<number[]>(() => {
        if (initialDatacenters && initialDatacenters.length > 0) {
            return initialDatacenters;
        }
        return [];
    });
    const [datacenterTouched, setDatacenterTouched] = useState(false);
    const [openAccordionItems, setOpenAccordionItems] = useState<CurationAccordionItemValue[]>(() =>
        normalizeAccordionItems(curationAccordionOpenItems ?? DEFAULT_OPEN_ACCORDION_ITEMS),
    );
    const openAccordionItemsRef = useRef(openAccordionItems);

    // State to trigger auto-switch to MSL tab when it becomes available
    const [shouldAutoSwitchToMsl, setAutoSwitchToMslState] = useState<boolean>(false);

    // Get current user's admin status to allow admins to edit DOI even after save
    // The prop is passed from the parent page component (editor.tsx) which has access to Inertia context
    const isAdmin = isUserAdmin ?? false;

    // Stable callback for setting auto-switch state
    const setShouldAutoSwitchToMsl = useCallback((value: boolean) => {
        setAutoSwitchToMslState(value);
    }, []);

    // Form validation hook
    const { validateField, markFieldTouched, getFieldState, getFieldMessages, setFieldErrors, clearBackendErrors } = useFormValidation();

    // DOI validation hook for duplicate checking
    const {
        isValidating: isDoiValidating,
        conflictData,
        showConflictModal,
        setShowConflictModal,
        validateDoi,
        resetValidation: resetDoiValidation,
        checkDoiBeforeSave,
    } = useDoiValidation({
        excludeResourceId: initialResourceId ? parseInt(initialResourceId, 10) : undefined,
        onSuccess: () => {
            toast.success('DOI ist verfügbar', { duration: 2000 });
        },
    });

    // Check if DOI field should be readonly (already saved with a valid DOI)
    // Admins can always edit the DOI field, even after the resource has been saved
    const isDoiReadonly = Boolean(initialResourceId && initialDoi && initialDoi.trim() !== '' && !isAdmin);

    // DOI validation rules (format only - duplicate check is done via useDoiValidation hook)
    // Memoized to prevent unnecessary callback recreations
    const doiValidationRules: ValidationRule[] = useMemo(
        () => [
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
        ],
        [],
    );

    // Handler to use the suggested DOI from the conflict modal
    // Note: The backend already verified this DOI is available, but we still need to
    // trigger format validation so the field shows the correct validation state
    const handleUseSuggestedDoi = useCallback(
        (suggestedDoi: string) => {
            setForm((prev) => ({ ...prev, doi: suggestedDoi }));
            // Trigger format validation to update the field's validation state
            markFieldTouched('doi');
            validateField({
                fieldId: 'doi',
                value: suggestedDoi,
                rules: doiValidationRules,
                // Use functional update pattern to get current form state
                formData: { doi: suggestedDoi },
            });
            // Clear any existing DOI conflict state since this is a verified available DOI
            resetDoiValidation();
            // Show success toast to confirm the DOI was accepted
            toast.success('Vorgeschlagene DOI übernommen', { duration: 2000 });
            // Note: 'form' is intentionally excluded - we use functional update for setForm
            // and only need the new DOI value for validation
        },
        [markFieldTouched, validateField, doiValidationRules, resetDoiValidation],
    );

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

    // Version validation rules (optional, aligned with DataCite/backend limits)
    const versionValidationRules: ValidationRule[] = [
        {
            validate: (value) => {
                if (!value || String(value).trim() === '') {
                    return null; // Version is optional
                }
                const result = validateVersion(String(value));
                if (!result.isValid) {
                    return { severity: 'error', message: result.error! };
                }
                return null;
            },
        },
    ];

    // Title validation rules
    const createTitleValidationRules = (index: number, titleType: string, allTitles: TitleEntry[]): ValidationRule[] => [
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
                const uniquenessResult = validateTitleUniqueness(allTitles.map((t) => ({ title: t.title, type: t.titleType })));
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

    // License validation rules (at least one complete license row or imported raw rights statement is required)
    const primaryLicenseValidationRules: ValidationRule<LicenseEntry[]>[] = [
        {
            validate: (value) => {
                if (!value.some(hasLicenseEntryEvidence)) {
                    return { severity: 'error', message: 'At least one license is required.' };
                }

                return null;
            },
        },
    ];

    // Abstract (Description) validation rules
    // Debounce prevents performance issues: validateRequired and validateTextLength are fast,
    // but frequent re-renders during rapid typing can cause lag. 300ms balances responsiveness
    // with preventing excessive validation calls during continuous typing.
    const abstractValidationRules: ValidationRule[] = [
        {
            debounce: 300,
            validate: (value) => {
                const text = String(value || '');

                // Required check
                const requiredResult = validateRequired(text, 'Abstract');
                if (!requiredResult.isValid) {
                    return { severity: 'error', message: requiredResult.error! };
                }

                // Length check (50-17500 characters)
                const lengthResult = validateTextLength(text, {
                    min: ABSTRACT_MIN_LENGTH,
                    max: ABSTRACT_MAX_LENGTH,
                    fieldName: 'Abstract',
                });
                if (!lengthResult.isValid) {
                    return { severity: 'error', message: lengthResult.error! };
                }

                // Warning at 90% of max length
                if (text.length > ABSTRACT_MAX_LENGTH * 0.9) {
                    return {
                        severity: 'warning',
                        message: `Abstract is very long (${text.length}/${ABSTRACT_MAX_LENGTH} characters). Consider condensing if possible.`,
                    };
                }

                return null;
            },
        },
    ];

    const [gcmdVocabularies, setGcmdVocabularies] = useState<{
        science: VocabularyKeyword[];
        platforms: VocabularyKeyword[];
        instruments: VocabularyKeyword[];
        msl: VocabularyKeyword[];
        chronostratigraphy: VocabularyKeyword[];
        gemet: VocabularyKeyword[];
        analytical_methods: VocabularyKeyword[];
        euroscivoc: VocabularyKeyword[];
    }>({
        science: [],
        platforms: [],
        instruments: [],
        msl: [],
        chronostratigraphy: [],
        gemet: [],
        analytical_methods: [],
        euroscivoc: [],
    });
    const [isLoadingVocabularies, setIsLoadingVocabularies] = useState(true);

    // Track which thesauri are enabled in settings
    const [thesauriAvailability, setThesauriAvailability] = useState<{
        science_keywords: boolean;
        platforms: boolean;
        instruments: boolean;
        chronostratigraphy: boolean;
        gemet: boolean;
        analytical_methods: boolean;
        euroscivoc: boolean;
    }>({
        science_keywords: true,
        platforms: true,
        instruments: true,
        chronostratigraphy: true,
        gemet: true,
        analytical_methods: true,
        euroscivoc: true,
    });
    const [pid4instAvailability, setPid4instAvailability] = useState<'checking' | 'available' | 'unavailable'>('checking');

    useEffect(() => {
        let isCancelled = false;

        const loadPidAvailability = async () => {
            try {
                const response = await fetch('/api/v1/vocabularies/pid-availability');

                if (!response.ok) {
                    if (!isCancelled) {
                        setPid4instAvailability('available');
                    }
                    return;
                }

                const availabilityData = (await response.json()) as {
                    pid4inst?: {
                        available?: boolean;
                    };
                };

                if (!isCancelled) {
                    setPid4instAvailability(availabilityData.pid4inst?.available === false ? 'unavailable' : 'available');
                }
            } catch (error) {
                if (!isCancelled) {
                    setPid4instAvailability('available');
                    console.warn('Failed to check PID availability, keeping PID-dependent fields visible', error);
                }
            }
        };

        void loadPidAvailability();

        return () => {
            isCancelled = true;
        };
    }, []);

    const shouldShowUsedInstrumentsSection = pid4instAvailability === 'available';

    // Load thesauri availability and GCMD vocabularies from web routes on mount
    useEffect(() => {
        const loadVocabularies = async () => {
            try {
                // First, check which thesauri are available
                let availability = {
                    science_keywords: true,
                    platforms: true,
                    instruments: true,
                    chronostratigraphy: true,
                    gemet: true,
                    analytical_methods: true,
                    euroscivoc: true,
                };
                try {
                    const availabilityRes = await fetch('/api/v1/vocabularies/thesauri-availability');
                    if (availabilityRes.ok) {
                        const availabilityData = await availabilityRes.json();
                        availability = {
                            science_keywords: availabilityData.science_keywords?.available ?? true,
                            platforms: availabilityData.platforms?.available ?? true,
                            instruments: availabilityData.instruments?.available ?? true,
                            chronostratigraphy: availabilityData.chronostratigraphy?.available ?? true,
                            gemet: availabilityData.gemet?.available ?? true,
                            analytical_methods: availabilityData.analytical_methods?.available ?? true,
                            euroscivoc: availabilityData.euroscivoc?.available ?? true,
                        };
                        setThesauriAvailability(availability);
                    }
                } catch {
                    // If availability check fails, assume all are available
                    console.warn('Failed to check thesauri availability, assuming all are enabled');
                }

                // Only fetch vocabularies that are enabled
                const fetchPromises: Promise<Response>[] = [];
                const fetchOrder: ('science' | 'platforms' | 'instruments' | 'chronostratigraphy' | 'gemet' | 'analytical_methods' | 'euroscivoc')[] =
                    [];

                if (availability.science_keywords) {
                    fetchPromises.push(fetch('/vocabularies/gcmd-science-keywords'));
                    fetchOrder.push('science');
                }
                if (availability.platforms) {
                    fetchPromises.push(fetch('/vocabularies/gcmd-platforms'));
                    fetchOrder.push('platforms');
                }
                if (availability.instruments) {
                    fetchPromises.push(fetch('/vocabularies/gcmd-instruments'));
                    fetchOrder.push('instruments');
                }
                if (availability.chronostratigraphy) {
                    fetchPromises.push(fetch('/vocabularies/chronostrat-timescale'));
                    fetchOrder.push('chronostratigraphy');
                }
                if (availability.gemet) {
                    fetchPromises.push(fetch('/vocabularies/gemet'));
                    fetchOrder.push('gemet');
                }
                if (availability.analytical_methods) {
                    fetchPromises.push(fetch('/vocabularies/analytical-methods'));
                    fetchOrder.push('analytical_methods');
                }
                if (availability.euroscivoc) {
                    fetchPromises.push(fetch('/vocabularies/euroscivoc'));
                    fetchOrder.push('euroscivoc');
                }

                if (fetchPromises.length === 0) {
                    // No thesauri enabled
                    setIsLoadingVocabularies(false);
                    return;
                }

                const responses = await Promise.all(fetchPromises);

                // Build vocabulary object based on successful responses
                const vocabularies: typeof gcmdVocabularies = {
                    science: [],
                    platforms: [],
                    instruments: [],
                    msl: [],
                    chronostratigraphy: [],
                    gemet: [],
                    analytical_methods: [],
                    euroscivoc: [],
                };

                // Process each response with its corresponding key
                const parsePromises = responses.map(async (response, index) => {
                    const key = fetchOrder[index];
                    if (response.ok) {
                        const data = await response.json();
                        return { key, data: data?.data || [] };
                    }
                    console.error(`Failed to load vocabulary: ${key}`);
                    return { key, data: [] };
                });

                const results = await Promise.all(parsePromises);
                for (const { key, data } of results) {
                    vocabularies[key] = data;
                }

                if (import.meta.env.DEV) {
                    console.debug('Loaded vocabularies:', {
                        science: vocabularies.science.length,
                        platforms: vocabularies.platforms.length,
                        instruments: vocabularies.instruments.length,
                        chronostratigraphy: vocabularies.chronostratigraphy.length,
                        gemet: vocabularies.gemet.length,
                        analytical_methods: vocabularies.analytical_methods.length,
                        euroscivoc: vocabularies.euroscivoc.length,
                        availability,
                    });
                }

                setGcmdVocabularies(vocabularies);
            } catch (error) {
                console.error('Error loading vocabularies:', error);
            } finally {
                setIsLoadingVocabularies(false);
            }
        };

        void loadVocabularies();
    }, []);

    const hasMslControlledKeywords = useMemo(() => {
        return gcmdKeywords.some((kw) => getVocabularyTypeFromScheme(kw.scheme) === 'msl');
    }, [gcmdKeywords]);

    // Check if MSL section should be shown based on Free Keywords or selected MSL controlled keywords
    const shouldShowMSLSection = useMemo(() => {
        const keywords = freeKeywords.map((k) => k.value.toLowerCase());
        const triggers = ['epos', 'multi-scale laboratories', 'multi scale laboratories', 'msl'];

        return hasMslControlledKeywords || keywords.some((keyword) => triggers.some((trigger) => keyword.includes(trigger)));
    }, [freeKeywords, hasMslControlledKeywords]);

    const visibleAccordionItemValues = useMemo<CurationAccordionItemValue[]>(() => {
        return CURATION_ACCORDION_ITEM_VALUES.filter((value) => {
            if (value === 'msl-laboratories') {
                return shouldShowMSLSection;
            }

            if (value === 'used-instruments') {
                return shouldShowUsedInstrumentsSection;
            }

            return true;
        });
    }, [shouldShowMSLSection, shouldShowUsedInstrumentsSection]);

    const visibleOpenAccordionItems = useMemo(
        () => normalizeAccordionItems(openAccordionItems, visibleAccordionItemValues),
        [openAccordionItems, visibleAccordionItemValues],
    );
    const allVisibleAccordionItemsOpen =
        visibleAccordionItemValues.length > 0 && visibleAccordionItemValues.every((value) => visibleOpenAccordionItems.includes(value));
    const allVisibleAccordionItemsClosed = visibleOpenAccordionItems.length === 0;

    const persistAccordionPreference = useCallback((items: readonly CurationAccordionItemValue[], immediate = false) => {
        const persist = () => {
            router.put(
                CURATION_ACCORDION_PREFERENCE_URL,
                {
                    open_items: [...items],
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['curationAccordionOpenItems'],
                },
            );
        };

        if (accordionPreferenceTimeoutRef.current) {
            clearTimeout(accordionPreferenceTimeoutRef.current);
            accordionPreferenceTimeoutRef.current = null;
        }

        if (immediate) {
            persist();
            return;
        }

        accordionPreferenceTimeoutRef.current = setTimeout(persist, 400);
    }, []);

    const updateOpenAccordionItems = useCallback(
        (
            nextItemsOrUpdater:
                | readonly CurationAccordionItemValue[]
                | ((currentItems: CurationAccordionItemValue[]) => readonly CurationAccordionItemValue[]),
            options: { immediate?: boolean; persist?: boolean; persistHiddenItems?: boolean } = {},
        ) => {
            const nextItems = typeof nextItemsOrUpdater === 'function' ? nextItemsOrUpdater(openAccordionItemsRef.current) : nextItemsOrUpdater;
            const normalizedItems = normalizeAccordionItems(nextItems);
            const persistedItems = options.persistHiddenItems
                ? normalizedItems
                : normalizeAccordionItems(normalizedItems, visibleAccordionItemValues);

            openAccordionItemsRef.current = normalizedItems;
            setOpenAccordionItems(normalizedItems);

            if (options.persist ?? true) {
                persistAccordionPreference(persistedItems, options.immediate);
            }
        },
        [persistAccordionPreference, visibleAccordionItemValues],
    );

    const handleAccordionValueChange = useCallback(
        (values: string[]) => {
            const visibleItems = normalizeAccordionItems(values, visibleAccordionItemValues);
            const visibleItemSet = new Set(visibleAccordionItemValues);
            const hiddenOpenItems = openAccordionItemsRef.current.filter((item) => !visibleItemSet.has(item));

            updateOpenAccordionItems([...visibleItems, ...hiddenOpenItems], { persistHiddenItems: true });
        },
        [updateOpenAccordionItems, visibleAccordionItemValues],
    );

    const collapseAllAccordionItems = useCallback(() => {
        const visibleItemSet = new Set(visibleAccordionItemValues);
        const hiddenOpenItems = openAccordionItemsRef.current.filter((item) => !visibleItemSet.has(item));

        updateOpenAccordionItems(hiddenOpenItems, { immediate: true, persistHiddenItems: true });
    }, [updateOpenAccordionItems, visibleAccordionItemValues]);

    const expandAllAccordionItems = useCallback(() => {
        const visibleItemSet = new Set(visibleAccordionItemValues);
        const hiddenOpenItems = openAccordionItemsRef.current.filter((item) => !visibleItemSet.has(item));

        updateOpenAccordionItems([...visibleAccordionItemValues, ...hiddenOpenItems], { immediate: true, persistHiddenItems: true });
    }, [updateOpenAccordionItems, visibleAccordionItemValues]);

    useEffect(() => {
        openAccordionItemsRef.current = openAccordionItems;
    }, [openAccordionItems]);

    useEffect(() => {
        return () => {
            if (accordionPreferenceTimeoutRef.current) {
                clearTimeout(accordionPreferenceTimeoutRef.current);
                accordionPreferenceTimeoutRef.current = null;
            }
        };
    }, []);

    // Load MSL vocabulary when MSL section becomes visible
    useEffect(() => {
        if (shouldShowMSLSection && gcmdVocabularies.msl.length === 0) {
            const loadMslVocabulary = async () => {
                try {
                    const response = await fetch('/vocabularies/msl');

                    if (!response.ok) {
                        console.error('Failed to load MSL vocabulary', response.status);
                        return;
                    }

                    const data = await response.json();

                    if (import.meta.env.DEV) {
                        console.debug('Loaded MSL vocabulary:', data.length || 0, 'root nodes');
                    }

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
        // Track if component is still mounted for async operations
        let isMounted = true;

        if (shouldShowMSLSection && !openAccordionItems.includes('msl-laboratories')) {
            updateOpenAccordionItems((prev) => [...prev, 'msl-laboratories'], { persist: false });

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

                // Handle scroll and tab-switch with timeouts that can be cancelled
                // First timeout: wait for accordion animation
                mslScrollTimeoutRef.current = setTimeout(() => {
                    if (!isMounted) return;

                    if (!openAccordionItems.includes('controlled-vocabularies')) {
                        // Open the controlled vocabularies accordion first
                        updateOpenAccordionItems((prev) => [...prev, 'controlled-vocabularies'], { persist: false });
                    }

                    // Scroll to the section
                    controlledVocabulariesRef.current?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start',
                    });

                    // Second timeout: wait for scroll animation to complete
                    // Uses separate ref to avoid overwriting the first timeout's ref
                    mslAnimationTimeoutRef.current = setTimeout(() => {
                        if (!isMounted) return;
                        // Reset auto-switch flag after animation completes
                        setShouldAutoSwitchToMsl(false);
                    }, 500);
                }, 300);
            }
        } else if (!shouldShowMSLSection && openAccordionItems.includes('msl-laboratories')) {
            updateOpenAccordionItems((prev) => prev.filter((item) => item !== 'msl-laboratories'), { persist: false });
            // Reset notification flag when MSL section is hidden
            hasNotifiedMslUnlock.current = false;
        }

        // Cleanup: cancel any pending timeouts when effect re-runs or component unmounts
        return () => {
            isMounted = false;
            if (mslScrollTimeoutRef.current) {
                clearTimeout(mslScrollTimeoutRef.current);
                mslScrollTimeoutRef.current = null;
            }
            if (mslAnimationTimeoutRef.current) {
                clearTimeout(mslAnimationTimeoutRef.current);
                mslAnimationTimeoutRef.current = null;
            }
        };
    }, [shouldShowMSLSection, openAccordionItems, setShouldAutoSwitchToMsl, updateOpenAccordionItems]);

    // MSL validation info - show recommendation when section is visible but no laboratories selected
    const mslValidationInfo = useMemo(() => {
        if (!shouldShowMSLSection) {
            return null; // Section not visible, no validation needed
        }

        if (mslLaboratories.length === 0) {
            return {
                severity: 'info' as const,
                message:
                    'This dataset is tagged with EPOS/MSL keywords. Consider adding originating multi-scale laboratories to improve discoverability.',
            };
        }

        return null; // Laboratories are selected, all good
    }, [shouldShowMSLSection, mslLaboratories.length]);

    const contributorPersonRoleNames = useMemo(() => contributorPersonRoles.map((role) => role.name), [contributorPersonRoles]);
    const contributorInstitutionRoleNames = useMemo(() => contributorInstitutionRoles.map((role) => role.name), [contributorInstitutionRoles]);
    const authorRoleNames = useMemo(
        () => authorRoles.map((role) => role.name.trim()).filter((name): name is string => name.length > 0),
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
    const authorRolesDescriptionId = authorRoleNames.length > 0 ? 'author-roles-description' : undefined;
    const { suggestions: affiliationSuggestions } = useRorAffiliations();

    const [isSaving, setIsSaving] = useState(false);
    const [isSavingDraft, setIsSavingDraft] = useState(false);
    const [draftAutosaveStatus, setDraftAutosaveStatus] = useState<DraftAutosaveStatus>('idle');
    const [lastDraftAutosaveAt, setLastDraftAutosaveAt] = useState<Date | null>(null);
    const draftAutosaveInFlightRef = useRef(false);
    const lastDraftAutosaveSignatureRef = useRef<string | null>(null);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [mappedValidationErrors, setMappedValidationErrors] = useState<MappedError[]>([]);
    const [validationAlertHeader, setValidationAlertHeader] = useState<string | undefined>(undefined);
    const [hasAttemptedSubmit, setHasAttemptedSubmit] = useState(false);

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
    const dateValidationIssues = useMemo(() => {
        const issues: string[] = [];

        // Validate each user-entered date
        dates.forEach((date, index) => {
            const dateIndex = index + 1;

            if (date.dateMode === 'range') {
                if (!isDateRangeCapable(date.dateType)) {
                    issues.push(`Date ${dateIndex}: ${date.dateType} does not support period entry`);
                }

                if (!date.startDate || date.startDate.trim() === '') {
                    issues.push(`Date ${dateIndex}: Start date is required for periods`);
                }

                if (!date.endDate || date.endDate.trim() === '') {
                    issues.push(`Date ${dateIndex}: End date is required for periods`);
                }
            }

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
            if (date.startDate && date.endDate && date.startDate.trim() !== '' && date.endDate.trim() !== '') {
                const start = new Date(date.startDate);
                const end = new Date(date.endDate);

                if (!isNaN(start.getTime()) && !isNaN(end.getTime()) && end < start) {
                    issues.push(`Date ${dateIndex}: End date must be after start date`);
                }
            }
        });

        return issues;
    }, [dates]);

    // Draft save only requires a Main Title (Issue #548)
    const isDraftSaveable = useMemo(() => {
        const mainTitleEntry = titles.find((entry) => entry.titleType === 'main-title');
        return Boolean(mainTitleEntry?.title.trim());
    }, [titles]);

    // Check if there are any legacy MSL keywords that need to be replaced
    const hasLegacyKeywords = useMemo(() => {
        return gcmdKeywords.some((kw) => kw.isLegacy === true);
    }, [gcmdKeywords]);

    const clientSubmitValidationErrors = useMemo(() => {
        const errors: Record<string, string[]> = {};
        const mainTitleEntry = titles.find((entry) => entry.titleType === 'main-title');

        if (!mainTitleEntry?.title.trim()) {
            appendValidationMessage(errors, 'titles.0.title', 'Main Title is required.');
        }

        if (!form.year?.trim()) {
            appendValidationMessage(errors, 'year', 'Publication Year is required.');
        }

        if (!form.resourceType) {
            appendValidationMessage(errors, 'resourceType', 'Resource Type is required.');
        }

        if (!form.language) {
            appendValidationMessage(errors, 'language', 'Language is required.');
        }

        if (!licenseEntries.some(hasLicenseEntryEvidence)) {
            appendValidationMessage(errors, 'licenses', 'At least one License is required.');
        }

        let customLicenseIndex = 0;
        licenseEntries.forEach((entry) => {
            if (!isCustomLicensePayloadEntry(entry)) {
                return;
            }

            if (!entry.name.trim()) {
                appendValidationMessage(errors, `customLicenses.${customLicenseIndex}.name`, 'Custom license name is required.');
            }

            if (!entry.uri.trim()) {
                appendValidationMessage(errors, `customLicenses.${customLicenseIndex}.uri`, 'Custom license URL is required.');
            } else if (!isHttpUrl(entry.uri.trim())) {
                appendValidationMessage(errors, `customLicenses.${customLicenseIndex}.uri`, 'Custom license URL must use http or https.');
            }

            customLicenseIndex += 1;
        });

        if (authors.length === 0) {
            appendValidationMessage(errors, 'authors', 'At least one author is required.');
        } else {
            authors.forEach((author, index) => {
                if (author.type === 'person') {
                    if (!author.lastName.trim()) {
                        appendValidationMessage(errors, `authors.${index}.lastName`, `Author ${index + 1}: Last name is required.`);
                    }

                    if (author.isContact && !author.email.trim()) {
                        appendValidationMessage(errors, `authors.${index}.email`, `Author ${index + 1}: Email is required for contact person.`);
                    }

                    return;
                }

                if (!author.institutionName.trim()) {
                    appendValidationMessage(errors, `authors.${index}.institutionName`, `Author ${index + 1}: Institution name is required.`);
                }
            });
        }

        const abstractEntry = descriptions.find((desc) => desc.type === 'Abstract');
        const abstractText = abstractEntry?.value.trim() ?? '';

        if (!abstractText) {
            appendValidationMessage(errors, 'descriptions.0.description', 'Abstract is required.');
        } else {
            const abstractLengthResult = validateTextLength(abstractText, {
                min: ABSTRACT_MIN_LENGTH,
                max: ABSTRACT_MAX_LENGTH,
                fieldName: 'Abstract',
            });

            if (!abstractLengthResult.isValid) {
                appendValidationMessage(errors, 'descriptions.0.description', abstractLengthResult.error!);
            }
        }

        if (selectedDatacenters.length === 0) {
            appendValidationMessage(errors, 'datacenters', 'At least one datacenter is required.');
        }

        dateValidationIssues.forEach((issue) => appendValidationMessage(errors, 'dates', issue));

        return errors;
    }, [authors, descriptions, form.language, form.resourceType, form.year, licenseEntries, selectedDatacenters, titles, dateValidationIssues]);

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
        const hasDatacenter = selectedDatacenters.length > 0;

        // Check if DOI has validation errors (if present)
        const doiMessages = getFieldState('doi').messages;
        const hasDoiError = doiMessages.some((msg) => msg.severity === 'error');

        // Check if Year has validation errors (if present)
        const yearMessages = getFieldState('year').messages;
        const hasYearError = yearMessages.some((msg) => msg.severity === 'error');

        // Check if Version has validation errors (if present)
        const versionMessages = getFieldState('version').messages;
        const hasVersionError = versionMessages.some((msg) => msg.severity === 'error');

        const allRequiredPresent = hasMainTitle && hasYear && hasResourceType && hasLanguage && hasDatacenter;
        const hasErrors = hasDoiError || hasYearError || hasVersionError;

        if (!allRequiredPresent || hasErrors) {
            return 'invalid';
        }
        return 'valid';
    }, [titles, form.year, form.resourceType, form.language, selectedDatacenters, getFieldState]);

    const licensesStatus = useMemo(() => {
        const hasCompleteLicense = licenseEntries.some(hasLicenseEntryEvidence);
        const hasInvalidCustomLicense = licenseEntries.some(
            (entry) => isCustomLicensePayloadEntry(entry) && (!entry.name.trim() || !entry.uri.trim() || !isHttpUrl(entry.uri.trim())),
        );

        if (!hasCompleteLicense || hasInvalidCustomLicense) {
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
        if (abstractEntry.value.trim().length < ABSTRACT_MIN_LENGTH) {
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
            (coverage) => coverage.latMin.trim() !== '' || coverage.lonMin.trim() !== '' || coverage.startDate.trim() !== '',
        );

        if (!hasAnyCoverage) {
            return 'optional-empty';
        }

        return 'valid';
    }, [spatialTemporalCoverages]);

    const datesStatus = useMemo(() => {
        // Dates are optional because DataCite does not require a user-entered editor date.
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

    const instrumentsStatus = useMemo(() => {
        // Instruments are optional
        if (instruments.length === 0) {
            return 'optional-empty';
        }
        return 'valid';
    }, [instruments]);

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

    const handleTitleChange = (index: number, field: keyof Omit<TitleEntry, 'id'>, value: string) => {
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
        setTitles((prev) => [...prev, { id: crypto.randomUUID(), title: '', titleType: defaultType }]);
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

    const validatePrimaryLicenseEntries = (entries: LicenseEntry[]) => {
        validateField({
            fieldId: 'license-0',
            value: entries,
            rules: primaryLicenseValidationRules,
            formData: form,
        });
    };

    const handleLicenseModeChange = (index: number, mode: LicenseEntry['mode']) => {
        setLicenseEntries((prev) => {
            const current = prev[index];
            if (!current || current.mode === mode) return prev;

            const next = [...prev];
            const updated: LicenseEntry =
                mode === 'custom'
                    ? { id: current.id, mode: 'custom', name: '', uri: '' }
                    : { id: current.id, mode: 'catalog', license: '' };

            next[index] = updated;

            validatePrimaryLicenseEntries(next);

            return next;
        });
    };

    const handleCatalogLicenseChange = (index: number, value: string) => {
        setLicenseEntries((prev) => {
            const current = prev[index];
            if (!current) return prev;

            const next = [...prev];
            const updated: LicenseEntry = { id: current.id, mode: 'catalog', license: value };
            next[index] = updated;

            validatePrimaryLicenseEntries(next);

            return next;
        });
    };

    const handleCustomLicenseChange = (index: number, field: 'name' | 'uri', value: string) => {
        setLicenseEntries((prev) => {
            const current = prev[index];
            if (!current || current.mode !== 'custom') return prev;

            const next = [...prev];
            const updated: LicenseEntry = { ...current, [field]: value };
            next[index] = updated;

            validatePrimaryLicenseEntries(next);

            return next;
        });
    };

    const addLicense = () => {
        if (licenseEntries.length >= MAX_LICENSES) return;
        setLicenseEntries((prev) => {
            const next: LicenseEntry[] = [...prev, { id: crypto.randomUUID(), mode: 'catalog', license: '' }];
            validatePrimaryLicenseEntries(next);
            return next;
        });
    };

    const removeLicense = (index: number) => {
        setLicenseEntries((prev) => {
            const next = prev.filter((_, i) => i !== index);
            validatePrimaryLicenseEntries(next);
            return next;
        });
    };

    const handleDateChange = (index: number, field: keyof Omit<DateEntry, 'id'>, value: string) => {
        setDates((prev) => {
            const current = prev[index];
            if (!current) return prev;

            const next = [...prev];
            let updated: DateEntry = { ...current };

            if (field === 'dateMode') {
                const dateMode: DateMode = value === 'range' && isDateRangeCapable(current.dateType) ? 'range' : 'single';
                updated = { ...updated, dateMode };

                if (dateMode === 'single') {
                    updated.endDate = null;
                    updated.endTime = null;
                    updated.endTimezone = null;
                }
            } else if (field === 'dateType') {
                updated = { ...updated, dateType: value };

                if (!isDateRangeCapable(value)) {
                    updated.dateMode = 'single';
                    updated.endDate = null;
                    updated.endTime = null;
                    updated.endTimezone = null;
                }
            } else if (field === 'startTimezone' || field === 'endTimezone') {
                updated = { ...updated, [field]: value === 'none' ? null : value };
            } else {
                updated = { ...updated, [field]: value };
            }

            next[index] = updated;
            return next;
        });
    };

    const addDate = () => {
        if (dates.length >= MAX_DATES) return;
        // Find the first unused date type or default to 'other'
        const usedTypes = new Set(dates.map((d) => normalizeDateTypeSlug(d.dateType)));
        const availableType =
            dateTypeOptions.find((dt) => !usedTypes.has(normalizeDateTypeSlug(dt.value)))?.value ?? dateTypeOptions[0]?.value ?? 'other';
        setDates((prev) => [
            ...prev,
            {
                id: crypto.randomUUID(),
                startDate: '',
                endDate: '',
                dateType: availableType,
                dateMode: 'single',
                startTime: null,
                endTime: null,
                startTimezone: null,
                endTimezone: null,
            },
        ]);
    };

    const removeDate = (index: number) => {
        setDates((prev) => prev.filter((_, i) => i !== index));
    };

    useEffect(() => {
        if (errorMessage && errorRef.current) {
            errorRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
            errorRef.current.focus();
        }
    }, [errorMessage]);

    const [resolvedResourceId, setResolvedResourceId] = useState<number | null>(() => {
        if (!initialResourceId) {
            return null;
        }

        const trimmed = initialResourceId.trim();

        if (!trimmed) {
            return null;
        }

        const parsed = Number(trimmed);

        return Number.isFinite(parsed) ? parsed : null;
    });

    const [landingPageForPreview, setLandingPageForPreview] = useState<EditorLandingPageSummary | null>(initialLandingPage);
    const [isPreparingLandingPagePreview, setIsPreparingLandingPagePreview] = useState(false);
    const [pendingLandingPageSetupResource, setPendingLandingPageSetupResource] = useState<LandingPagePreviewSetupResource | null>(null);
    const [isLandingPageSetupOpen, setIsLandingPageSetupOpen] = useState(false);

    const saveUrl = useMemo(() => store.url(), []);
    const draftSaveUrl = useMemo(() => storeDraft.url(), []);
    const resourcesUrl = useMemo(() => resources.url(), []);
    const mainTitleForLandingPage = useMemo(() => {
        return titles.find((title) => title.titleType === MAIN_TITLE_SLUG)?.title.trim() || titles[0]?.title.trim() || undefined;
    }, [titles]);
    const selectedResourceTypeName = useMemo(() => {
        return resourceTypes.find((type) => String(type.id) === form.resourceType)?.name;
    }, [form.resourceType, resourceTypes]);
    const buildLandingPageSetupResource = useCallback(
        (resourceId: number): LandingPagePreviewSetupResource => ({
            id: resourceId,
            doi: form.doi?.trim() || null,
            title: mainTitleForLandingPage,
            resourcetypegeneral: selectedResourceTypeName,
        }),
        [form.doi, mainTitleForLandingPage, selectedResourceTypeName],
    );
    const openLandingPagePreview = useCallback((landingPage: LandingPagePreviewTarget, preopenedWindow?: Window | null) => {
        const previewTarget = getLandingPagePreviewTarget(landingPage);

        if (!previewTarget) {
            preopenedWindow?.close();
            toast.error(getLandingPagePreviewMissingUrlMessage(landingPage));
            return;
        }

        if (preopenedWindow) {
            preopenedWindow.location.href = previewTarget;
            return;
        }

        const openedWindow = window.open(previewTarget, '_blank', 'noopener,noreferrer');

        if (!openedWindow) {
            toast.error(LANDING_PAGE_POPUP_BLOCKED_MESSAGE);
        }
    }, []);
    const draftAutosaveMessage = useMemo(() => {
        if (draftAutosaveStatus === 'idle') {
            return null;
        }

        if (draftAutosaveStatus === 'saving') {
            return 'Autosaving draft...';
        }

        if (draftAutosaveStatus === 'error') {
            return 'Autosave failed';
        }

        const savedAt = lastDraftAutosaveAt?.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return savedAt ? 'Draft autosaved at ' + savedAt : 'Draft autosaved';
    }, [draftAutosaveStatus, lastDraftAutosaveAt]);

    // Shared payload builder for both Save & Validate and Save Draft (Issue #548)
    const buildPayload = useCallback(() => {
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

                    const hasContactPersonRole = contributor.roles.some((role) => role.value.replace(/\s+/g, '').toLowerCase() === 'contactperson');

                    return {
                        type: 'person',
                        orcid: orcid || null,
                        firstName: firstName || null,
                        lastName,
                        email: hasContactPersonRole ? contributor.email.trim() || null : null,
                        website: hasContactPersonRole ? contributor.website.trim() || null : null,
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
            titles: { title: string; titleType: string; language?: string | null }[];
            licenses: string[];
            customLicenses: { name: string; uri: string; sourceResourceRightId?: number | null }[];
            authors: SerializedAuthor[];
            contributors: SerializedContributor[];
            mslLaboratories: {
                identifier: string;
                name: string;
                affiliation_name: string;
                affiliation_ror: string | null;
            }[];
            descriptions: { descriptionType: string; description: string; language?: string | null }[];
            dates: { dateType: string; dateMode: DateMode; startDate: string | null; endDate: string | null }[];
            freeKeywords: string[];
            gcmdKeywords: {
                id: string;
                text: string;
                path: string;
                language: string;
                scheme: string;
                schemeURI: string;
                classificationCode?: string;
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
                relationTypeInformation?: string;
                citationLabel?: string;
            }[];
            fundingReferences: {
                funderName: string;
                funderIdentifier: string;
                funderIdentifierType: string | null;
                awardNumber: string;
                awardUri: string;
                awardTitle: string;
            }[];
            instruments: {
                pid: string;
                pidType: string;
                name: string;
            }[];
            datacenters: number[];
            resourceId?: number;
            rawRights: DataCiteFormProps['initialRawRights'];
        } = {
            doi: form.doi?.trim() || null,
            year: form.year ? Number(form.year) : null,
            resourceType: form.resourceType ? Number(form.resourceType) : null,
            version: form.version?.trim() || null,
            language: form.language,
            titles: titles.map((entry) => ({
                title: entry.title,
                titleType: entry.titleType,
                language: entry.language ?? null,
            })),
            licenses: licenseEntries
                .filter(isCatalogLicensePayloadEntry)
                .map((entry) => entry.license),
            customLicenses: licenseEntries
                .filter(isCustomLicensePayloadEntry)
                .map((entry) => ({
                    name: entry.name.trim(),
                    uri: entry.uri.trim(),
                    ...(entry.sourceResourceRightId != null ? { sourceResourceRightId: entry.sourceResourceRightId } : {}),
                })),
            rawRights: licenseEntries
                .filter(isRawRightsOnlyLicenseEntry)
                .map(serializeRawRightsOnlyLicenseEntry),
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
                    language: desc.language ?? null,
                })),
            dates: dates.filter(hasValidDateValue).map((date) => ({
                dateType: date.dateType,
                dateMode: date.dateMode,
                startDate: buildDateTime(date.startDate ?? '', date.startTime, date.startTimezone) || null,
                endDate: date.dateMode === 'range' ? buildDateTime(date.endDate ?? '', date.endTime, date.endTimezone) || null : null,
            })),
            freeKeywords: freeKeywords.map((kw) => kw.value.trim()).filter((kw) => kw.length > 0),
            gcmdKeywords: gcmdKeywords.map((kw) => ({
                id: kw.id,
                text: kw.text,
                path: kw.path,
                language: kw.language,
                scheme: kw.scheme,
                schemeURI: kw.schemeURI,
                ...(kw.classificationCode != null && kw.classificationCode.trim() !== '' ? { classificationCode: kw.classificationCode.trim() } : {}),
                vocabularyType: getVocabularyTypeFromScheme(kw.scheme),
            })),
            spatialTemporalCoverages: spatialTemporalCoverages.map((coverage) => ({
                type: coverage.type,
                polygonPoints: coverage.polygonPoints?.map((p) => ({ lat: p.lat, lon: p.lon })),
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
                ...(rw.relation_type_information ? { relationTypeInformation: rw.relation_type_information } : {}),
                ...(rw.citation_label ? { citationLabel: rw.citation_label } : {}),
            })),
            // Pass-through for XML-imported inline citations; the backend
            // persists these on first save, after which the REST-based
            // CitationManagerModal owns the data.
            ...(initialRelatedItems && initialRelatedItems.length > 0 ? { relatedItems: initialRelatedItems } : {}),
            fundingReferences: fundingReferences.map((funding) => ({
                funderName: funding.funderName,
                funderIdentifier: funding.funderIdentifier,
                funderIdentifierType: funding.funderIdentifierType,
                awardNumber: funding.awardNumber,
                awardUri: funding.awardUri,
                awardTitle: funding.awardTitle,
            })),
            instruments: instruments.map((inst) => ({
                pid: inst.pid,
                pidType: inst.pidType,
                name: inst.name,
            })),
            datacenters: selectedDatacenters,
        };

        if (resolvedResourceId !== null) {
            payload.resourceId = resolvedResourceId;
        }

        return payload;
    }, [
        authors,
        contributors,
        dates,
        descriptions,
        form.doi,
        form.language,
        form.resourceType,
        form.version,
        form.year,
        freeKeywords,
        fundingReferences,
        gcmdKeywords,
        initialRelatedItems,
        instruments,
        licenseEntries,
        mslLaboratories,
        relatedWorks,
        resolvedResourceId,
        selectedDatacenters,
        spatialTemporalCoverages,
        titles,
    ]);

    const updateDraftAutosaveSignature = useCallback((payload: ReturnType<typeof buildPayload>, resourceId?: number) => {
        const savedPayload = resourceId ? { ...payload, resourceId } : payload;

        try {
            lastDraftAutosaveSignatureRef.current = JSON.stringify(savedPayload);
        } catch (error) {
            console.error('Failed to serialize draft autosave signature', error);
            lastDraftAutosaveSignatureRef.current = null;
        }
    }, []);

    const markDraftAutosaveSaved = useCallback(
        (payload: ReturnType<typeof buildPayload>, resourceId?: number) => {
            updateDraftAutosaveSignature(payload, resourceId);
            setLastDraftAutosaveAt(new Date());
            setDraftAutosaveStatus('saved');
        },
        [updateDraftAutosaveSignature],
    );

    useEffect(() => {
        if (resolvedResourceId === null || lastDraftAutosaveSignatureRef.current !== null) {
            return;
        }

        try {
            lastDraftAutosaveSignatureRef.current = JSON.stringify(buildPayload());
        } catch (error) {
            console.error('Failed to initialize draft autosave signature', error);
            lastDraftAutosaveSignatureRef.current = null;
        }
    }, [buildPayload, resolvedResourceId]);

    const saveDraftSilently = useCallback(async () => {
        if (!isDraftSaveable || dateValidationIssues.length > 0 || isSaving || isSavingDraft || isPreparingLandingPagePreview || draftAutosaveInFlightRef.current) {
            return;
        }

        let payload: ReturnType<typeof buildPayload>;
        let signature: string;

        try {
            payload = buildPayload();
            signature = JSON.stringify(payload);
        } catch (error) {
            console.error('Failed to prepare draft autosave payload', error);
            setDraftAutosaveStatus('error');
            return;
        }

        if (signature === lastDraftAutosaveSignatureRef.current) {
            return;
        }

        draftAutosaveInFlightRef.current = true;
        setDraftAutosaveStatus('saving');

        try {
            const response = await axios.post(draftSaveUrl, payload, {
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
            });

            const data = response.data as DraftSaveResponse | null;
            const savedResourceId = data?.resource?.id;

            if (savedResourceId) {
                setResolvedResourceId(savedResourceId);
            }

            markDraftAutosaveSaved(payload, savedResourceId);
        } catch (error) {
            console.error('Failed to autosave draft', error);
            setDraftAutosaveStatus('error');
        } finally {
            draftAutosaveInFlightRef.current = false;
        }
    }, [buildPayload, dateValidationIssues.length, draftSaveUrl, isDraftSaveable, isPreparingLandingPagePreview, isSaving, isSavingDraft, markDraftAutosaveSaved]);

    useEffect(() => {
        const autosaveTimerId = window.setInterval(() => {
            void saveDraftSilently();
        }, DRAFT_AUTOSAVE_INTERVAL_MS);

        return () => window.clearInterval(autosaveTimerId);
    }, [saveDraftSilently]);

    const revealValidationErrors = useCallback(
        (errors: Record<string, string[]>, headerMessage: string) => {
            const mapped = mapBackendErrors(errors);
            setMappedValidationErrors(mapped);
            setValidationAlertHeader(headerMessage);

            if (Object.keys(errors).some(isDatacenterErrorKey)) {
                setDatacenterTouched(true);
            }

            // Inject errors into individual field states for inline display
            const fieldErrors = mapped.filter((e) => e.fieldId).map((e) => ({ fieldId: e.fieldId!, message: e.message }));
            if (fieldErrors.length > 0) {
                setFieldErrors(fieldErrors);
            }

            // Auto-open accordion sections that have errors
            const sectionsWithErrors = [...new Set(mapped.map((e) => e.sectionId))];
            updateOpenAccordionItems((prev) => [...new Set([...prev, ...sectionsWithErrors])] as CurationAccordionItemValue[], { persist: false });

            // Scroll to first errored field/section after accordion opens
            if (sectionsWithErrors.length > 0) {
                const firstError = mapped[0];
                scheduleScrollToError(firstError.fieldSelector, firstError.sectionId);
            }

            setErrorMessage(headerMessage);
        },
        [setFieldErrors, updateOpenAccordionItems],
    );

    const datacenterErrorMessage = useMemo(() => {
        if (!datacenterTouched) {
            return null;
        }

        const mappedDatacenterElementError = mappedValidationErrors.find((error) => isDatacenterElementErrorKey(error.backendKey));
        if (mappedDatacenterElementError) {
            return mappedDatacenterElementError.message;
        }

        if (selectedDatacenters.length === 0) {
            const mappedDatacenterCollectionError = mappedValidationErrors.find((error) => error.backendKey === 'datacenters');
            if (mappedDatacenterCollectionError) {
                return mappedDatacenterCollectionError.message;
            }

            return 'At least one datacenter is required.';
        }

        return null;
    }, [datacenterTouched, mappedValidationErrors, selectedDatacenters.length]);

    const clearDatacenterValidationErrors = useCallback(() => {
        const nextMappedValidationErrors = mappedValidationErrors.filter((error) => !isDatacenterErrorKey(error.backendKey));

        if (nextMappedValidationErrors.length === mappedValidationErrors.length) {
            return;
        }

        setMappedValidationErrors(nextMappedValidationErrors);

        if (nextMappedValidationErrors.length === 0) {
            setValidationAlertHeader(undefined);
            setErrorMessage(null);
        }
    }, [mappedValidationErrors]);

    /**
     * Shared handler for 422 backend validation errors.
     * Maps errors to sections, injects inline field errors, opens relevant accordion sections,
     * and scrolls/focuses the first errored field or section trigger.
     */
    const applyBackendValidationErrors = useCallback(
        (errors: Record<string, string[]>, serverMessage: string | undefined, defaultHeader: string) => {
            revealValidationErrors(errors, serverMessage ?? defaultHeader);
        },
        [revealValidationErrors],
    );

    const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setHasAttemptedSubmit(true);
        setIsSaving(true);
        setErrorMessage(null);
        setMappedValidationErrors([]);
        clearBackendErrors();

        // Check client-side submit blockers before sending the request.
        if (Object.keys(clientSubmitValidationErrors).length > 0) {
            revealValidationErrors(clientSubmitValidationErrors, 'Please complete all required fields before saving.');
            setIsSaving(false);
            return;
        }

        // Client-side validation for funding references
        if (!validateAllFundingReferences(fundingReferences)) {
            revealValidationErrors(
                {
                    fundingReferences: ['Please fix the validation errors in the Funding References section before submitting.'],
                },
                'Please review the highlighted funding reference issues before saving.',
            );
            setIsSaving(false);
            return;
        }

        // Pre-submit DOI duplicate check: verify the DOI is still available before saving
        const doiValue = form.doi?.trim();
        if (doiValue) {
            const conflict = await checkDoiBeforeSave(doiValue);
            if (conflict) {
                // DOI already exists — show conflict modal instead of saving
                setIsSaving(false);
                return;
            }
        }

        const payload = buildPayload();

        try {
            const response = await axios.post(saveUrl, payload, {
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
            });

            // Extended response type for DataCite sync (Issue #383)
            interface SaveResponse {
                message?: string;
                resource?: { id: number };
                dataCiteSync?: {
                    attempted: boolean;
                    success: boolean;
                    errorMessage: string | null;
                    doi: string | null;
                };
                warning?: string;
            }

            const data = response.data as SaveResponse | null;
            const successMsg = data?.message || 'Successfully saved resource.';

            // Persist the resource ID so subsequent saves update rather than duplicate (PR #639 review)
            if (data?.resource?.id) {
                setResolvedResourceId(data.resource.id);
            }

            setHasAttemptedSubmit(false);

            // Show toasts before redirect so feedback is visible even if navigation fails
            toast.success(successMsg);

            // DataCite sync feedback via toast notifications
            if (data?.dataCiteSync?.attempted) {
                if (data.dataCiteSync.success) {
                    toast.success('DataCite metadata synchronized', {
                        description: data.dataCiteSync.doi ? `DOI ${data.dataCiteSync.doi} has been updated.` : 'Metadata has been updated.',
                        duration: 4000,
                    });
                } else {
                    toast.warning('DataCite update failed', {
                        description: data.dataCiteSync.errorMessage || 'Please try manually later.',
                        duration: 8000,
                    });
                }
            }

            // Redirect to resources list (Issue #624)
            router.visit(resourcesUrl, {
                onError: () => {
                    toast.warning('Could not navigate to the resources list. Your data has been saved.');
                },
            });
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const response = error.response;

                if (response?.status === 419) {
                    console.error('CSRF token mismatch detected');
                    setErrorMessage('Your session has expired. Please refresh the page and try again.');
                    // The axios interceptor in app.tsx will handle the page reload
                    return;
                }

                if (response) {
                    const defaultError = 'Unable to save resource. Please review the highlighted issues.';
                    const parsed = response.data as { message?: string; errors?: Record<string, string[]> } | null;

                    // Fallback: If the backend returns a DOI validation error (e.g. race condition
                    // where another user saved the same DOI between our pre-submit check and the save),
                    // re-run the DOI duplicate check to show the conflict modal if applicable.
                    if (response.status === 422 && parsed?.errors?.doi && form.doi) {
                        const conflict = await checkDoiBeforeSave(form.doi);
                        if (conflict) {
                            // Conflict modal is now shown by checkDoiBeforeSave — skip generic error rendering
                            return;
                        }
                        // Not a duplicate — fall through to normal validation error rendering
                    }

                    if (parsed?.errors) {
                        applyBackendValidationErrors(parsed.errors, parsed.message, defaultError);
                    } else {
                        setErrorMessage(parsed?.message || defaultError);
                    }

                    return;
                }
            }

            console.error('Failed to save resource', error);
            setErrorMessage('A network error prevented saving the resource. Please try again.');
        } finally {
            setIsSaving(false);
        }
    };

    // Save draft with relaxed validation - only requires Main Title (Issue #548)
    const handleSaveDraft = async () => {
        if (!isDraftSaveable) return;

        setIsSavingDraft(true);
        setErrorMessage(null);
        setMappedValidationErrors([]);
        clearBackendErrors();

        if (dateValidationIssues.length > 0) {
            setHasAttemptedSubmit(true);
            revealValidationErrors({ dates: dateValidationIssues }, 'Please resolve the date validation issues before saving your draft.');
            setIsSavingDraft(false);
            return;
        }

        const payload = buildPayload();

        try {
            const response = await axios.post(draftSaveUrl, payload, {
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
            });

            const data = response.data as DraftSaveResponse | null;
            const successMsg = data?.message || 'Draft saved successfully.';

            // Persist the resource ID so subsequent saves update rather than duplicate (PR #639 review)
            const savedResourceId = data?.resource?.id;
            if (savedResourceId) {
                setResolvedResourceId(savedResourceId);
            }
            updateDraftAutosaveSignature(payload, savedResourceId);

            setHasAttemptedSubmit(false);

            // Show toast before redirect so feedback is visible even if navigation fails
            toast.success(successMsg);

            // Redirect to resources list (Issue #624)
            router.visit(resourcesUrl, {
                onError: () => {
                    toast.warning('Could not navigate to the resources list. Your draft has been saved.');
                },
            });
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const response = error.response;

                if (response?.status === 419) {
                    setErrorMessage('Your session has expired. Please refresh the page and try again.');
                    return;
                }

                if (response) {
                    const defaultError = 'Unable to save draft. Please review the highlighted issues.';
                    const parsed = response.data as { message?: string; errors?: Record<string, string[]> } | null;

                    if (parsed?.errors) {
                        applyBackendValidationErrors(parsed.errors, parsed.message, defaultError);
                    } else {
                        setErrorMessage(parsed?.message || defaultError);
                    }

                    return;
                }
            }

            console.error('Failed to save draft', error);
            setErrorMessage('A network error prevented saving the draft. Please try again.');
        } finally {
            setIsSavingDraft(false);
        }
    };

    const saveDraftForLandingPagePreview = useCallback(async (): Promise<{ resourceId: number } | null> => {
        if (!isDraftSaveable) return null;

        setIsPreparingLandingPagePreview(true);
        setErrorMessage(null);
        setMappedValidationErrors([]);
        clearBackendErrors();

        if (dateValidationIssues.length > 0) {
            setHasAttemptedSubmit(true);
            revealValidationErrors({ dates: dateValidationIssues }, 'Please resolve the date validation issues before opening the landing page preview.');
            setIsPreparingLandingPagePreview(false);
            return null;
        }

        const payload = buildPayload();

        try {
            const response = await axios.post(draftSaveUrl, payload, {
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
            });

            const data = response.data as DraftSaveResponse | null;
            const savedResourceId = data?.resource?.id ?? resolvedResourceId;

            if (!savedResourceId) {
                setErrorMessage('Unable to open landing page preview because the draft resource ID is missing.');
                return null;
            }

            setResolvedResourceId(savedResourceId);
            updateDraftAutosaveSignature(payload, savedResourceId);
            setHasAttemptedSubmit(false);

            return { resourceId: savedResourceId };
        } catch (error) {
            if (axios.isAxiosError(error)) {
                const response = error.response;

                if (response?.status === 419) {
                    setErrorMessage('Your session has expired. Please refresh the page and try again.');
                    return null;
                }

                if (response) {
                    const defaultError = 'Unable to save draft before opening the landing page preview.';
                    const parsed = response.data as { message?: string; errors?: Record<string, string[]> } | null;

                    if (parsed?.errors) {
                        applyBackendValidationErrors(parsed.errors, parsed.message, defaultError);
                    } else {
                        setErrorMessage(parsed?.message || defaultError);
                    }

                    return null;
                }
            }

            console.error('Failed to save draft before opening landing page preview', error);
            setErrorMessage('A network error prevented saving the draft before opening the landing page preview. Please try again.');
            return null;
        } finally {
            setIsPreparingLandingPagePreview(false);
        }
    }, [
        applyBackendValidationErrors,
        buildPayload,
        clearBackendErrors,
        dateValidationIssues,
        draftSaveUrl,
        isDraftSaveable,
        resolvedResourceId,
        revealValidationErrors,
        updateDraftAutosaveSignature,
    ]);

    const handleShowLandingPagePreview = useCallback(async () => {
        let preopenedPreviewWindow: Window | null = null;

        if (landingPageForPreview) {
            preopenedPreviewWindow = openLandingPagePreviewPlaceholder();

            if (!preopenedPreviewWindow) {
                toast.error(LANDING_PAGE_POPUP_BLOCKED_MESSAGE);
                return;
            }
        }

        const result = await saveDraftForLandingPagePreview();

        if (!result) {
            preopenedPreviewWindow?.close();
            return;
        }

        if (landingPageForPreview) {
            openLandingPagePreview(landingPageForPreview, preopenedPreviewWindow);
            return;
        }

        setPendingLandingPageSetupResource(buildLandingPageSetupResource(result.resourceId));
        setIsLandingPageSetupOpen(true);
    }, [buildLandingPageSetupResource, landingPageForPreview, openLandingPagePreview, saveDraftForLandingPagePreview]);

    const handleCloseLandingPageSetup = useCallback(() => {
        setIsLandingPageSetupOpen(false);
        setPendingLandingPageSetupResource(null);
    }, []);

    const handleLandingPageSetupSuccess = useCallback(
        (landingPage?: LandingPageConfig | null, preopenedPreviewWindow?: Window | null) => {
            if (landingPage) {
                const summary = toEditorLandingPageSummary(landingPage);
                setLandingPageForPreview(summary);
                openLandingPagePreview(summary, preopenedPreviewWindow);
            } else {
                preopenedPreviewWindow?.close();
                setLandingPageForPreview(null);
            }

            setIsLandingPageSetupOpen(false);
            setPendingLandingPageSetupResource(null);
        },
        [openLandingPagePreview],
    );

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

    const renderAccordionActions = () => (
        <>
            {!allVisibleAccordionItemsClosed && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-xs"
                            aria-label="Collapse all field groups"
                            data-testid="collapse-all-field-groups"
                            onClick={collapseAllAccordionItems}
                        >
                            <ChevronsUp className="h-3.5 w-3.5" aria-hidden="true" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Collapse all field groups</TooltipContent>
                </Tooltip>
            )}
            {!allVisibleAccordionItemsOpen && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-xs"
                            aria-label="Expand all field groups"
                            data-testid="expand-all-field-groups"
                            onClick={expandAllAccordionItems}
                        >
                            <ChevronsDown className="h-3.5 w-3.5" aria-hidden="true" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Expand all field groups</TooltipContent>
                </Tooltip>
            )}
        </>
    );

    const renderSectionActions = (label: string, tooltip?: string) => (
        <>
            <SectionHelpAction label={label} tooltip={tooltip} />
            {renderAccordionActions()}
        </>
    );

    const editorActionButtonClassName = 'h-8 px-3 text-xs sm:h-9 sm:px-4 sm:text-sm';
    const showSaveDraftDisabledTooltip = !isDraftSaveable && !isSavingDraft;
    const showLandingPagePreviewDisabledTooltip = !isDraftSaveable && !isPreparingLandingPagePreview;
    const showSaveValidateDisabledTooltip = hasLegacyKeywords && !isSaving;

    const renderEditorActions = () => (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span tabIndex={showSaveDraftDisabledTooltip ? 0 : undefined}>
                        {/* Save Draft is intentionally NOT disabled by hasLegacyKeywords.
                            Drafts are partial saves; legacy keyword replacement is only
                            required for full validation (Save & Validate). */}
                        <Button
                            type="button"
                            variant="outline"
                            className={editorActionButtonClassName}
                            data-testid="save-draft-button"
                            disabled={!isDraftSaveable || isSavingDraft || isSaving || isPreparingLandingPagePreview}
                            aria-busy={isSavingDraft}
                            onClick={handleSaveDraft}
                        >
                            <Save className="mr-2 h-4 w-4" />
                            {isSavingDraft ? 'Saving...' : 'Save Draft'}
                        </Button>
                    </span>
                </TooltipTrigger>
                {showSaveDraftDisabledTooltip && (
                    <TooltipContent side="top" align="end" className="max-w-sm">
                        <p className="text-sm">Enter a Main Title to save as draft.</p>
                    </TooltipContent>
                )}
            </Tooltip>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span tabIndex={showLandingPagePreviewDisabledTooltip ? 0 : undefined}>
                        <Button
                            type="button"
                            variant="outline"
                            className={editorActionButtonClassName}
                            data-testid="show-lp-preview-button"
                            disabled={!isDraftSaveable || isSavingDraft || isSaving || isPreparingLandingPagePreview}
                            aria-busy={isPreparingLandingPagePreview}
                            onClick={() => void handleShowLandingPagePreview()}
                        >
                            <Eye className="mr-2 h-4 w-4" />
                            {isPreparingLandingPagePreview ? 'Preparing...' : 'Show LP Preview'}
                        </Button>
                    </span>
                </TooltipTrigger>
                {showLandingPagePreviewDisabledTooltip && (
                    <TooltipContent side="top" align="end" className="max-w-sm">
                        <p className="text-sm">Enter a Main Title to preview the landing page.</p>
                    </TooltipContent>
                )}
            </Tooltip>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span tabIndex={showSaveValidateDisabledTooltip ? 0 : undefined}>
                        <Button
                            type="submit"
                            className={editorActionButtonClassName}
                            data-testid="save-resource-button"
                            disabled={isSaving || isSavingDraft || isPreparingLandingPagePreview || hasLegacyKeywords}
                            aria-busy={isSaving}
                            aria-disabled={isSaving || isSavingDraft || isPreparingLandingPagePreview || hasLegacyKeywords}
                        >
                            {isSaving ? 'Saving...' : 'Save & Validate'}
                        </Button>
                    </span>
                </TooltipTrigger>
                {showSaveValidateDisabledTooltip && (
                    <TooltipContent side="top" align="end" className="max-w-sm">
                        <div className="space-y-2">
                            <p className="text-sm font-semibold">Cannot save: Legacy keywords detected</p>
                            <p className="text-xs">Please replace all legacy MSL keywords with keywords from the current vocabulary.</p>
                        </div>
                    </TooltipContent>
                )}
            </Tooltip>
        </>
    );

    // Build global error messages array for ValidationAlert when no mapped navigation errors are present.
    const globalErrorMessages = useMemo(() => {
        if (errorMessage && mappedValidationErrors.length === 0) {
            return [errorMessage];
        }

        return [];
    }, [errorMessage, mappedValidationErrors]);

    // Handle click on an error in the ClickableValidationAlert (Issue #605)
    const handleErrorClick = useCallback(
        (error: MappedError) => {
            // 1. Open the accordion section
            updateOpenAccordionItems(
                (prev) =>
                    prev.includes(error.sectionId as CurationAccordionItemValue) ? prev : [...prev, error.sectionId as CurationAccordionItemValue],
                { persist: false },
            );

            // 2. Scroll to field or section after DOM update (wait for accordion animation)
            scheduleScrollToError(error.fieldSelector, error.sectionId);
        },
        [updateOpenAccordionItems],
    );

    return (
        <form onSubmit={handleSubmit} noValidate className="space-y-6 pb-36 sm:pb-28 lg:pb-24">
            {mappedValidationErrors.length > 0 ? (
                <ClickableValidationAlert
                    ref={errorRef}
                    errors={mappedValidationErrors}
                    onErrorClick={handleErrorClick}
                    headerMessage={validationAlertHeader}
                    focusable
                    className="p-4"
                    data-testid="global-validation-alert"
                />
            ) : (
                <ValidationAlert
                    ref={errorRef}
                    severity="error"
                    messages={globalErrorMessages}
                    assertive
                    focusable
                    className="p-4"
                    data-testid="global-validation-alert"
                />
            )}
            <Accordion type="multiple" value={visibleOpenAccordionItems} onValueChange={handleAccordionValueChange} className="w-full">
                <AccordionItem value="resource-info">
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions(
                            'Resource Information',
                            'Required fields: Year, Resource Type, Main Title, Language, Datacenter',
                        )}
                    >
                        <AccordionSectionHeader
                            label="Resource Information"
                            description="Basic metadata about your dataset including identifiers and type."
                            required
                            status={renderStatusBadge(resourceInfoStatus)}
                        />
                    </AccordionTrigger>
                    <AccordionContent className="space-y-6">
                        <div className="grid gap-4 md:grid-cols-12">
                            <InputField
                                id="doi"
                                label="DOI"
                                value={form.doi || ''}
                                onChange={(e) => handleChange('doi', e.target.value)}
                                onBlur={(e) => {
                                    // Trigger format validation (shows errors immediately)
                                    handleFieldBlur('doi', e.target.value, doiValidationRules);
                                    // Trigger async DOI validation (duplicate check)
                                    if (e.target.value.trim()) {
                                        validateDoi(e.target.value);
                                    }
                                }}
                                validationMessages={getFieldState('doi').messages}
                                touched={getFieldState('doi').touched}
                                placeholder="10.xxxx/xxxxx"
                                labelTooltip={
                                    isDoiReadonly
                                        ? 'DOI cannot be changed after the resource has been saved. Only administrators can edit the DOI.'
                                        : initialResourceId && initialDoi && isAdmin
                                          ? 'As an administrator, you can edit this DOI. Be careful when changing registered DOIs.'
                                          : 'Enter DOI in format 10.xxxx/xxxxx or https://doi.org/10.xxxx/xxxxx'
                                }
                                className="md:col-span-3"
                                readOnly={isDoiReadonly}
                                disabled={isDoiValidating}
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
                                className="md:col-span-1"
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
                                className="md:col-span-3"
                                required
                                data-testid="resource-type-select"
                            />
                            <DatacenterField
                                id="datacenter"
                                label="Datacenter"
                                options={availableDatacenters}
                                selected={selectedDatacenters}
                                onChange={(ids) => {
                                    setSelectedDatacenters(ids);
                                    setDatacenterTouched(true);
                                    clearDatacenterValidationErrors();
                                }}
                                className="min-w-0 md:col-span-3"
                                required
                                hasError={datacenterErrorMessage !== null}
                                errorMessage={datacenterErrorMessage ?? undefined}
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
                                labelTooltip="Version number (e.g., 1.0, 2.1, 1.0.0)"
                                maxLength={50}
                                className="min-w-0 md:col-span-1"
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
                        <div className="mt-3 space-y-4">
                            {titles.map((entry, index) => (
                                <TitleField
                                    key={entry.id}
                                    id={entry.id}
                                    title={entry.title}
                                    titleType={entry.titleType}
                                    options={titleTypes
                                        .filter((t) => t.slug !== 'main-title' || !mainTitleUsed || entry.titleType === 'main-title')
                                        .map((t) => ({ value: t.slug, label: t.name }))}
                                    onTitleChange={(val) => handleTitleChange(index, 'title', val)}
                                    onTypeChange={(val) => handleTitleChange(index, 'titleType', val)}
                                    onAdd={addTitle}
                                    onRemove={() => removeTitle(index)}
                                    isFirst={index === 0}
                                    canAdd={canAddTitle(titles, MAX_TITLES)}
                                    validationMessages={getFieldState(`title-${index}`).messages}
                                    touched={getFieldState(`title-${index}`).touched}
                                    onValidationBlur={() =>
                                        handleFieldBlur(`title-${index}`, entry.title, createTitleValidationRules(index, entry.titleType, titles))
                                    }
                                />
                            ))}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="licenses-rights">
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions(
                            'Licenses and Rights',
                            'At least one license is required. Choose a license that matches your data sharing policy.',
                        )}
                    >
                        <AccordionSectionHeader
                            label="Licenses and Rights"
                            description="Specify usage rights and restrictions for your dataset."
                            required
                            counter={{ current: licenseEntries.length, max: MAX_LICENSES }}
                            status={renderStatusBadge(licensesStatus)}
                        />
                    </AccordionTrigger>
                    <AccordionContent>
                        <div className="space-y-4">
                            {licenseEntries.map((entry, index) => {
                                const customLicensePayloadIndex = entry.mode === 'custom' ? customLicensePayloadIndexesByEntryId.get(entry.id) : undefined;

                                return (
                                    <LicenseField
                                        key={entry.id}
                                        id={entry.id}
                                        entry={entry}
                                        options={licenses.map((l) => ({
                                            value: l.identifier,
                                            label: l.name,
                                        }))}
                                        onModeChange={(mode) => handleLicenseModeChange(index, mode)}
                                        onCatalogLicenseChange={(val) => handleCatalogLicenseChange(index, val)}
                                        onCustomLicenseChange={(field, val) => handleCustomLicenseChange(index, field, val)}
                                        onAdd={addLicense}
                                        onRemove={() => removeLicense(index)}
                                        isFirst={index === 0}
                                        canAdd={canAddLicenseEntry(licenseEntries, MAX_LICENSES)}
                                        required={index === 0}
                                        customNameRequired={index === 0}
                                        customUriRequired={index === 0 && !isRawRightsOnlyLicenseEntry(entry)}
                                        validationMessages={index === 0 ? getFieldState('license-0').messages : undefined}
                                        touched={index === 0 ? getFieldState('license-0').touched : undefined}
                                        onValidationBlur={index === 0 ? () => markFieldTouched('license-0') : undefined}
                                        data-testid={`license-select-${index}`}
                                        customNameTestId={customLicensePayloadIndex !== undefined ? `custom-license-name-${customLicensePayloadIndex}` : undefined}
                                        customUriTestId={customLicensePayloadIndex !== undefined ? `custom-license-uri-${customLicensePayloadIndex}` : undefined}
                                    />
                                );
                            })}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="authors">
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions('Authors', 'At least one author is required. Drag to reorder authors.')}
                    >
                        <AccordionSectionHeader
                            label="Authors"
                            description="People or institutions who created this work."
                            required
                            counter={{ current: authors.length, max: 100 }}
                            status={renderStatusBadge(authorsStatus)}
                        />
                    </AccordionTrigger>
                    <AccordionContent>
                        {/* Validation issues notification (only after save attempt — Issue #625) */}
                        {hasAttemptedSubmit && <ValidationAlert severity="error" title="Required fields missing" messages={authorValidationIssues} />}
                        {authorRoleNames.length > 0 && (
                            <p id={authorRolesDescriptionId} className="mb-4 text-sm text-muted-foreground" data-testid="author-roles-availability">
                                {`The available author ${authorRoleNames.length === 1 ? 'role is' : 'roles are'} ${authorRoleSummary}.`}
                            </p>
                        )}
                        <AuthorField authors={authors} onChange={setAuthors} affiliationSuggestions={affiliationSuggestions} />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="contributors">
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions(
                            'Contributors',
                            'Optional. Contributors can have different roles like Editor, Data Curator, etc.',
                        )}
                    >
                        <AccordionSectionHeader
                            label="Contributors"
                            description="Additional people who contributed to this work."
                            counter={{ current: contributors.length, max: 100 }}
                            status={renderStatusBadge(contributorsStatus)}
                        />
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
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions(
                            'Descriptions',
                            `Abstract is required (${ABSTRACT_MIN_LENGTH}-${ABSTRACT_MAX_LENGTH.toLocaleString('en-US')} characters). Other description types are optional.`,
                        )}
                    >
                        <AccordionSectionHeader
                            label="Descriptions"
                            description="Detailed information about your dataset."
                            required
                            status={renderStatusBadge(descriptionsStatus)}
                        />
                    </AccordionTrigger>
                    <AccordionContent>
                        <DescriptionField
                            descriptions={descriptions}
                            onChange={handleDescriptionChange}
                            availableTypes={descriptionTypes}
                            abstractValidationMessages={getFieldMessages('abstract')}
                            abstractTouched={getFieldState('abstract').touched}
                            onAbstractValidationBlur={() => markFieldTouched('abstract')}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="controlled-vocabularies" ref={controlledVocabulariesRef}>
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions('Controlled Vocabularies', 'Improves discoverability by using NASA GCMD and MSL keywords.')}
                    >
                        <AccordionSectionHeader
                            label="Controlled Vocabularies"
                            description="Select keywords from standardized vocabularies."
                            counter={{ current: gcmdKeywords.length, max: 100 }}
                            status={renderStatusBadge(controlledVocabulariesStatus)}
                        />
                    </AccordionTrigger>
                    <AccordionContent>
                        {isLoadingVocabularies ? (
                            <div className="py-8 text-center text-muted-foreground">Loading vocabularies...</div>
                        ) : (
                            <ControlledVocabulariesField
                                scienceKeywords={gcmdVocabularies.science}
                                platforms={gcmdVocabularies.platforms}
                                instruments={gcmdVocabularies.instruments}
                                mslVocabulary={gcmdVocabularies.msl}
                                chronostratVocabulary={gcmdVocabularies.chronostratigraphy}
                                gemetVocabulary={gcmdVocabularies.gemet}
                                analyticalMethodsVocabulary={gcmdVocabularies.analytical_methods}
                                euroscivocVocabulary={gcmdVocabularies.euroscivoc}
                                selectedKeywords={gcmdKeywords}
                                onChange={setGcmdKeywords}
                                showMslTab={shouldShowMSLSection}
                                showChronostratTab={thesauriAvailability.chronostratigraphy}
                                showGemetTab={thesauriAvailability.gemet}
                                showAnalyticalMethodsTab={thesauriAvailability.analytical_methods}
                                showEuroSciVocTab={thesauriAvailability.euroscivoc}
                                autoSwitchToMsl={shouldAutoSwitchToMsl}
                                enabledThesauri={thesauriAvailability}
                            />
                        )}
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="free-keywords">
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions('Free Keywords', 'Separate keywords with commas or press Enter.')}
                    >
                        <AccordionSectionHeader
                            label="Free Keywords"
                            description="Custom keywords for your dataset."
                            counter={{ current: freeKeywords.length, max: 100 }}
                            status={renderStatusBadge(freeKeywordsStatus)}
                        />
                    </AccordionTrigger>
                    <AccordionContent>
                        <FreeKeywordsField keywords={freeKeywords} onChange={setFreeKeywords} />
                    </AccordionContent>
                </AccordionItem>
                {shouldShowMSLSection && (
                    <AccordionItem value="msl-laboratories">
                        <AccordionTrigger
                            className={SECTION_TRIGGER_CLASS_NAME}
                            actions={renderSectionActions(
                                'Originating Multi-Scale Laboratories',
                                'Appears when EPOS/MSL keywords are detected in your dataset.',
                            )}
                        >
                            <AccordionSectionHeader
                                label="Originating Multi-Scale Laboratories"
                                description="Select associated EPOS/MSL laboratories."
                                counter={{ current: mslLaboratories.length, max: 20 }}
                                badge={<span className="rounded-md bg-secondary px-2 py-0.5 text-xs font-medium">EPOS/MSL</span>}
                                status={renderStatusBadge(mslLaboratoriesStatus)}
                            />
                        </AccordionTrigger>
                        <AccordionContent>
                            {mslValidationInfo && <ValidationAlert severity="info" title="Recommendation" messages={[mslValidationInfo.message]} />}
                            <MSLLaboratoriesField selectedLaboratories={mslLaboratories} onChange={setMslLaboratories} />
                        </AccordionContent>
                    </AccordionItem>
                )}
                <AccordionItem value="spatial-temporal-coverage">
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions(
                            'Spatial and Temporal Coverage',
                            'Supports points, boxes, and polygons for geographic coverage.',
                        )}
                    >
                        <AccordionSectionHeader
                            label="Spatial and Temporal Coverage"
                            description="Geographic and time boundaries of your dataset."
                            counter={{ current: spatialTemporalCoverages.length, max: 50 }}
                            status={renderStatusBadge(spatialTemporalCoverageStatus)}
                        />
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
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions('Dates', 'Add dates like collection period, validity, or other relevant temporal information.')}
                    >
                        <AccordionSectionHeader
                            label="Dates"
                            description="Important dates for your dataset."
                            counter={{ current: dates.length, max: MAX_DATES }}
                            status={renderStatusBadge(datesStatus)}
                        />
                    </AccordionTrigger>
                    <AccordionContent>
                        {hasAttemptedSubmit && <ValidationAlert severity="error" title="Date validation issues" messages={dateValidationIssues} />}
                        <div className="space-y-4">
                            {dates.length === 0 ? (
                                <EmptyState
                                    icon={<Calendar className="h-8 w-8" />}
                                    title="No dates added"
                                    description="Add important dates like collection period, validity, or other relevant temporal information."
                                    action={{
                                        label: 'Add Date',
                                        onClick: addDate,
                                    }}
                                    data-testid="dates-empty-state"
                                />
                            ) : (
                                dates.map((entry, index) => {
                                    const selectedDateType = dateTypeOptions.find(
                                        (dt) => normalizeDateTypeSlug(dt.value) === normalizeDateTypeSlug(entry.dateType),
                                    );
                                    return (
                                        <DateField
                                            key={entry.id}
                                            id={entry.id}
                                            startDate={entry.startDate}
                                            endDate={entry.endDate}
                                            dateType={entry.dateType}
                                            dateMode={entry.dateMode}
                                            startTime={entry.startTime}
                                            endTime={entry.endTime}
                                            startTimezone={entry.startTimezone}
                                            endTimezone={entry.endTimezone}
                                            dateTypeDescription={selectedDateType?.description}
                                            options={dateTypeOptions.filter(
                                                (dt) =>
                                                    normalizeDateTypeSlug(dt.value) === normalizeDateTypeSlug(entry.dateType) ||
                                                    !dates.some((d) => normalizeDateTypeSlug(d.dateType) === normalizeDateTypeSlug(dt.value)),
                                            )}
                                            onStartDateChange={(val) => handleDateChange(index, 'startDate', val)}
                                            onEndDateChange={(val) => handleDateChange(index, 'endDate', val)}
                                            onStartTimeChange={(val) => handleDateChange(index, 'startTime', val)}
                                            onEndTimeChange={(val) => handleDateChange(index, 'endTime', val)}
                                            onStartTimezoneChange={(val) => handleDateChange(index, 'startTimezone', val)}
                                            onEndTimezoneChange={(val) => handleDateChange(index, 'endTimezone', val)}
                                            onTypeChange={(val) => handleDateChange(index, 'dateType', val)}
                                            onDateModeChange={(val) => handleDateChange(index, 'dateMode', val)}
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
                <AccordionItem value="related-work" data-testid="related-work-section">
                    <AccordionTrigger
                        data-testid="related-work-accordion-trigger"
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions(
                            'Related Work',
                            'DOIs, URLs, Handles, and other DataCite identifier types are supported. Add entries, refine citation labels, and drag cards to reorder them.',
                        )}
                    >
                        <AccordionSectionHeader
                            label="Related Work"
                            description="Links to related publications and datasets."
                            counter={{ current: relatedWorks.length, max: 100 }}
                            status={renderStatusBadge(relatedWorkStatus)}
                        />
                    </AccordionTrigger>
                    <AccordionContent data-testid="related-work-accordion-content">
                        <RelatedWorkField
                            relatedWorks={relatedWorks}
                            onChange={setRelatedWorks}
                            activeRelationTypes={activeRelationTypes}
                            activeIdentifierTypes={activeIdentifierTypes}
                        />
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="citations" data-testid="citations-section">
                    <AccordionTrigger
                        data-testid="citations-accordion-trigger"
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions(
                            RELATED_ITEMS_SECTION_LABEL,
                            RELATED_ITEMS_SECTION_HELP,
                        )}
                    >
                        <AccordionSectionHeader
                            label={RELATED_ITEMS_SECTION_LABEL}
                            description={RELATED_ITEMS_SECTION_DESCRIPTION}
                        />
                    </AccordionTrigger>
                    <AccordionContent data-testid="citations-accordion-content">
                        <CitationsField resourceId={resolvedResourceId} />
                    </AccordionContent>
                </AccordionItem>
                {shouldShowUsedInstrumentsSection && (
                    <AccordionItem value="used-instruments" data-testid="used-instruments-section">
                        <AccordionTrigger
                            data-testid="used-instruments-accordion-trigger"
                            className={SECTION_TRIGGER_CLASS_NAME}
                            actions={renderSectionActions(
                                'Used Instruments',
                                'Select instruments from the PID4INST / b2inst registry. Instruments will be linked via Handle PIDs as DataCite relatedIdentifiers.',
                            )}
                        >
                            <AccordionSectionHeader
                                label="Used Instruments"
                                description="Research instruments used for data collection."
                                counter={{ current: instruments.length, max: 100 }}
                                status={renderStatusBadge(instrumentsStatus)}
                            />
                        </AccordionTrigger>
                        <AccordionContent data-testid="used-instruments-accordion-content">
                            <UsedInstrumentsField selectedInstruments={instruments} onChange={setInstruments} />
                        </AccordionContent>
                    </AccordionItem>
                )}
                <AccordionItem value="funding-references">
                    <AccordionTrigger
                        className={SECTION_TRIGGER_CLASS_NAME}
                        actions={renderSectionActions(
                            'Funding References',
                            'ROR lookup available for funder identification. Include grant numbers when available.',
                        )}
                    >
                        <AccordionSectionHeader
                            label="Funding References"
                            description="Grant and funder information."
                            counter={{ current: fundingReferences.length, max: 50 }}
                            status={renderStatusBadge(fundingReferencesStatus)}
                        />
                    </AccordionTrigger>
                    <AccordionContent id="funding-references-section">
                        <FundingReferenceField value={fundingReferences} onChange={setFundingReferences} />
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
            <ValidationAlert
                severity="warning"
                title="Legacy Keywords Detected"
                messages={
                    hasLegacyKeywords
                        ? [
                              'This dataset contains MSL keywords from the old database that don\'t exist in the current vocabulary. Please review the highlighted keywords in the "Controlled Vocabularies" section and replace them with keywords from the current MSL vocabulary before saving.',
                          ]
                        : []
                }
            />
            <div
                data-testid="editor-floating-actions"
                className="group fixed right-2 bottom-2 z-40 flex max-w-[calc(100vw-1rem)] flex-col items-end gap-2 p-2 sm:right-4 sm:bottom-4 sm:max-w-[calc(100vw-2rem)] lg:right-6 lg:bottom-6 lg:p-0"
            >
                <div
                    data-testid="editor-floating-actions-panel"
                    className="flex max-w-full flex-wrap justify-end gap-2 opacity-20 transition-opacity duration-200 ease-out group-hover:opacity-100 hover:opacity-100 focus-within:opacity-100 sm:gap-3 lg:opacity-100 [@media(hover:none)]:opacity-100"
                >
                    {renderEditorActions()}
                </div>
                {draftAutosaveMessage && (
                    <p
                        className={`${draftAutosaveStatus === 'error' ? 'text-xs text-destructive' : 'text-xs text-muted-foreground'} max-w-[calc(100vw-1rem)] text-right opacity-20 transition-opacity duration-200 ease-out group-hover:opacity-100 group-focus-within:opacity-100 lg:opacity-100 [@media(hover:none)]:opacity-100`}
                        data-testid="draft-autosave-status"
                        aria-live="polite"
                    >
                        {draftAutosaveMessage}
                    </p>
                )}
            </div>
            {pendingLandingPageSetupResource && (
                <SetupLandingPageModal
                    resource={pendingLandingPageSetupResource}
                    isOpen={isLandingPageSetupOpen}
                    onClose={handleCloseLandingPageSetup}
                    onSuccess={handleLandingPageSetupSuccess}
                    openPreviewOnSuccess={true}
                />
            )}
            {/* DOI Conflict Modal */}
            {conflictData && (
                <DoiConflictModal
                    open={showConflictModal}
                    onOpenChange={setShowConflictModal}
                    existingDoi={conflictData.existingDoi}
                    existingResourceTitle={conflictData.existingResourceTitle}
                    existingResourceId={conflictData.existingResourceId}
                    lastAssignedDoi={conflictData.lastAssignedDoi}
                    suggestedDoi={conflictData.suggestedDoi}
                    hasSuggestion={conflictData.hasSuggestion}
                    onUseSuggested={handleUseSuggestedDoi}
                />
            )}
        </form>
    );
}

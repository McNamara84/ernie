/**
 * useOrcidAutofill Hook
 *
 * Shared ORCID verification, auto-fill, and suggestion logic for Authors and Contributors.
 * Eliminates ~150 lines of duplicated code between author-item.tsx and contributor-item.tsx.
 *
 * Features:
 * - Auto-verify ORCID when a valid format is entered
 * - Auto-suggest ORCIDs based on name and affiliations
 * - Auto-fill name/email only when fields are empty
 * - Store additional ORCID data (affiliations, name diffs) as pending for curator review
 */

import { useCallback, useEffect, useMemo, useState } from 'react';

import { type OrcidAffiliation, type OrcidSearchResult, OrcidService } from '@/services/orcid';
import type { AffiliationTag } from '@/types/affiliations';

/**
 * Base entry interface - minimal fields shared between Person and Institution entries
 */
export interface BaseEntry {
    id: string;
    type: 'person' | 'institution';
    affiliations: AffiliationTag[];
    affiliationsInput: string;
}

/**
 * Person entry interface - fields available on person entries
 */
export interface PersonEntry extends BaseEntry {
    type: 'person';
    orcid: string;
    firstName: string;
    lastName: string;
    orcidVerified?: boolean;
    orcidVerifiedAt?: string | null;
}

/**
 * Result of ORCID data fetch - fields that can be auto-filled
 */
export interface OrcidAutofillData {
    firstName?: string;
    lastName?: string;
    email?: string;
    affiliations?: AffiliationTag[];
    affiliationsInput?: string;
    orcidVerified: boolean;
    orcidVerifiedAt: string;
}

/**
 * A single pending ORCID affiliation that the curator can review
 */
export interface PendingOrcidAffiliation {
    /** Organization name from ORCID */
    value: string;
    /** ROR ID if resolved (from ORCID API or reverse lookup) */
    rorId: string | null;
    /** Whether this is new (not in current affiliations) or different (same org, different name) */
    status: 'new' | 'different';
    /** If status='different': the current affiliation value it differs from */
    existingValue?: string;
    /** If resolved via ROR: the preferred label from ROR registry */
    resolvedName?: string;
}

/**
 * Data from ORCID that is available but not auto-applied — pending curator review
 */
export interface PendingOrcidData {
    /** Affiliations from ORCID that aren't in the current entry */
    affiliations: PendingOrcidAffiliation[];
    /** Name difference: ORCID firstName differs from current */
    firstNameDiff: { orcid: string; current: string } | null;
    /** Name difference: ORCID lastName differs from current */
    lastNameDiff: { orcid: string; current: string } | null;
    /** Email from ORCID not in current entry (authors only) */
    emailSuggestion: string | null;
}

/**
 * Data selected by the curator to apply from the pending ORCID suggestions
 */
export interface SelectedPendingData {
    affiliations: PendingOrcidAffiliation[];
    applyFirstName: boolean;
    applyLastName: boolean;
    applyEmail: boolean;
}

/**
 * Hook configuration
 * T must be a union type where at least one member is a PersonEntry
 */
export interface UseOrcidAutofillConfig<T extends BaseEntry> {
    /** Current entry (author or contributor) */
    entry: T;
    /** Callback when entry should be updated */
    onEntryChange: (updated: T) => void;
    /** Whether user has interacted with name fields (prevents auto-suggest on load) */
    hasUserInteracted: boolean;
    /** Whether to include email in autofill (Authors only) */
    includeEmail?: boolean;
}

/**
 * Error type for differentiated error handling
 */
export type OrcidErrorType = 'format' | 'checksum' | 'not_found' | 'api_error' | 'timeout' | 'network' | 'unknown' | null;

/**
 * Hook return value
 */
export interface UseOrcidAutofillReturn {
    /** Whether ORCID is being verified */
    isVerifying: boolean;
    /** Verification error message */
    verificationError: string | null;
    /** Clear verification error */
    clearError: () => void;
    /** ORCID suggestions based on name search */
    orcidSuggestions: OrcidSearchResult[];
    /** Whether suggestions are loading */
    isLoadingSuggestions: boolean;
    /** Whether to show suggestions dropdown */
    showSuggestions: boolean;
    /** Hide suggestions dropdown */
    hideSuggestions: () => void;
    /** Handle selecting an ORCID from search dialog or suggestions */
    handleOrcidSelect: (orcid: string) => Promise<void>;
    /** Whether a retry is available (for timeout/api errors) */
    canRetry: boolean;
    /** Trigger manual retry of ORCID verification */
    retryVerification: () => void;
    /** Error type for differentiated display */
    errorType: OrcidErrorType;
    /** Whether ORCID format and checksum are valid (offline validation) */
    isFormatValid: boolean;
    /** Additional ORCID data available for curator review (null = nothing pending) */
    pendingOrcidData: PendingOrcidData | null;
    /** Clear all pending ORCID data (discard) */
    clearPendingOrcidData: () => void;
    /** Apply selected pending data to the entry */
    applyPendingData: (selected: SelectedPendingData) => void;
}

/**
 * Response shape from POST /api/v1/ror-resolve
 */
interface RorResolveResult {
    name: string;
    rorId: string;
    matchedName: string;
}

/**
 * Resolve organization names to ROR IDs via backend API.
 * Returns a map of lowercased name → { rorId, matchedName }.
 */
async function resolveNamesToRor(names: string[]): Promise<Map<string, { rorId: string; matchedName: string }>> {
    const map = new Map<string, { rorId: string; matchedName: string }>();

    // Deduplicate names (case-insensitive) before sending
    const uniqueNames = [...new Set(names.map((n) => n.trim()).filter(Boolean))];
    if (uniqueNames.length === 0) return map;

    // Chunk into batches of 20 to match backend validation limit
    const BATCH_SIZE = 20;
    const batches: string[][] = [];
    for (let i = 0; i < uniqueNames.length; i += BATCH_SIZE) {
        batches.push(uniqueNames.slice(i, i + BATCH_SIZE));
    }

    for (const batch of batches) {
        try {
            const response = await fetch('/api/v1/ror-resolve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ names: batch }),
            });

            if (response.ok) {
                const data: { results: RorResolveResult[] } = await response.json();
                for (const result of data.results) {
                    map.set(result.name.toLowerCase().trim(), {
                        rorId: result.rorId,
                        matchedName: result.matchedName,
                    });
                }
            }
        } catch {
            // Silently fail — we'll proceed without ROR resolution for this batch
        }
    }

    return map;
}

/**
 * Compare ORCID affiliations against existing entry affiliations.
 * Returns pending items (new + different) instead of merging directly.
 */
async function computePendingAffiliations(
    orcidAffiliations: OrcidAffiliation[],
    existingAffiliations: AffiliationTag[],
): Promise<PendingOrcidAffiliation[]> {
    const pending: PendingOrcidAffiliation[] = [];

    // Build lookup structures from existing affiliations
    const existingByRor = new Map<string, AffiliationTag>();
    const existingByName = new Map<string, AffiliationTag>();
    for (const aff of existingAffiliations) {
        if (aff.rorId) existingByRor.set(aff.rorId, aff);
        existingByName.set(aff.value.toLowerCase().trim(), aff);
    }

    // Filter to affiliations with a name
    const namedAffiliations = orcidAffiliations.filter((a) => a.name);

    // Try to resolve names without ROR IDs to ROR IDs via backend
    const unresolvedNames = namedAffiliations.filter((a) => !a.rorId).map((a) => a.name!);
    const resolvedMap = await resolveNamesToRor(unresolvedNames);

    // Deduplicate ORCID affiliations among themselves (by ROR ID)
    const seenRorIds = new Set<string>();

    for (const orcidAff of namedAffiliations) {
        const name = orcidAff.name!;
        const nameLower = name.toLowerCase().trim();

        // Determine ROR ID: from ORCID API or resolved via backend
        const rorId = orcidAff.rorId ?? resolvedMap.get(nameLower)?.rorId ?? null;
        const resolvedName = resolvedMap.get(nameLower)?.matchedName ?? null;

        // Skip duplicates among ORCID results themselves
        if (rorId) {
            if (seenRorIds.has(rorId)) continue;
            seenRorIds.add(rorId);
        }

        // Case 1: ROR match — same institution exists in entry (possibly different name)
        if (rorId && existingByRor.has(rorId)) {
            const existing = existingByRor.get(rorId)!;
            if (existing.value.toLowerCase().trim() !== nameLower) {
                // Same institution (by ROR), different name spelling
                pending.push({
                    value: name,
                    rorId,
                    status: 'different',
                    existingValue: existing.value,
                    resolvedName: resolvedName ?? undefined,
                });
            }
            // Exact match by ROR + name → already present, skip
            continue;
        }

        // Case 2: Exact name match (case-insensitive)
        if (existingByName.has(nameLower)) {
            continue;
        }

        // Case 3: Resolved name matches an existing entry by ROR
        if (rorId && resolvedName) {
            const resolvedLower = resolvedName.toLowerCase().trim();
            if (existingByName.has(resolvedLower)) {
                const existing = existingByName.get(resolvedLower)!;
                if (existing.value.toLowerCase().trim() !== nameLower) {
                    pending.push({
                        value: name,
                        rorId,
                        status: 'different',
                        existingValue: existing.value,
                        resolvedName,
                    });
                }
                continue;
            }
        }

        // Case 4: Truly new affiliation
        pending.push({
            value: name,
            rorId,
            status: 'new',
            resolvedName: resolvedName ?? undefined,
        });
    }

    return pending;
}

/**
 * Type guard to check if entry is a PersonEntry
 */
function isPersonEntry<T extends BaseEntry>(entry: T): entry is T & PersonEntry {
    return entry.type === 'person';
}

/**
 * Process ORCID data after successful fetch: auto-fill empty fields and compute pending data.
 */
async function processOrcidData<T extends BaseEntry>(
    personEntry: T & PersonEntry,
    data: { firstName: string; lastName: string; emails: string[]; affiliations: OrcidAffiliation[] },
    includeEmail: boolean,
): Promise<{ updatedEntry: T; pendingData: PendingOrcidData | null }> {
    const updatedEntry = {
        ...personEntry,
        orcidVerified: true,
        orcidVerifiedAt: new Date().toISOString(),
    } as T;

    // Auto-fill name ONLY when fields are empty
    if (!personEntry.firstName && data.firstName) {
        (updatedEntry as unknown as PersonEntry).firstName = data.firstName;
    }
    if (!personEntry.lastName && data.lastName) {
        (updatedEntry as unknown as PersonEntry).lastName = data.lastName;
    }

    // Auto-fill email ONLY when field is empty (authors only)
    if (includeEmail && data.emails.length > 0 && 'email' in personEntry && !personEntry.email) {
        (updatedEntry as T & { email: string }).email = data.emails[0];
    }

    // Compute pending affiliations (not auto-applied)
    const pendingAffiliations = await computePendingAffiliations(data.affiliations, personEntry.affiliations);

    // Compute name differences (only when fields are NOT empty)
    const firstNameDiff =
        personEntry.firstName && data.firstName && personEntry.firstName.trim() !== data.firstName.trim()
            ? { orcid: data.firstName, current: personEntry.firstName }
            : null;

    const lastNameDiff =
        personEntry.lastName && data.lastName && personEntry.lastName.trim() !== data.lastName.trim()
            ? { orcid: data.lastName, current: personEntry.lastName }
            : null;

    // Compute email suggestion (only when email field exists and is NOT empty)
    const emailSuggestion =
        includeEmail && data.emails.length > 0 && 'email' in personEntry && personEntry.email && personEntry.email !== data.emails[0]
            ? data.emails[0]
            : null;

    const hasPendingData = pendingAffiliations.length > 0 || firstNameDiff !== null || lastNameDiff !== null || emailSuggestion !== null;

    return {
        updatedEntry,
        pendingData: hasPendingData
            ? {
                  affiliations: pendingAffiliations,
                  firstNameDiff,
                  lastNameDiff,
                  emailSuggestion,
              }
            : null,
    };
}

/**
 * useOrcidAutofill - Shared hook for ORCID verification and auto-fill
 *
 * Affiliations from ORCID are NOT auto-merged. Instead, they are stored as
 * pendingOrcidData for the curator to review via the OrcidSuggestionsModal.
 */
export function useOrcidAutofill<T extends BaseEntry>({
    entry,
    onEntryChange,
    hasUserInteracted,
    includeEmail = false,
}: UseOrcidAutofillConfig<T>): UseOrcidAutofillReturn {
    // Verification state
    const [isVerifying, setIsVerifying] = useState(false);
    const [verificationError, setVerificationError] = useState<string | null>(null);
    const [errorType, setErrorType] = useState<OrcidErrorType>(null);
    const [canRetry, setCanRetry] = useState(false);

    // Suggestion state
    const [orcidSuggestions, setOrcidSuggestions] = useState<OrcidSearchResult[]>([]);
    const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false);
    const [showSuggestions, setShowSuggestions] = useState(false);

    // Pending ORCID data for curator review
    const [pendingOrcidData, setPendingOrcidData] = useState<PendingOrcidData | null>(null);

    // Track retry trigger
    const [retryTrigger, setRetryTrigger] = useState(0);

    const clearError = useCallback(() => {
        setVerificationError(null);
        setErrorType(null);
        setCanRetry(false);
    }, []);
    const hideSuggestions = useCallback(() => setShowSuggestions(false), []);
    const clearPendingOrcidData = useCallback(() => setPendingOrcidData(null), []);

    // Check if current ORCID has valid format and checksum (offline validation)
    const isFormatValid = useMemo(() => {
        if (!isPersonEntry(entry) || !entry.orcid?.trim()) {
            return false;
        }
        return OrcidService.isValidFormat(entry.orcid) && OrcidService.validateChecksum(entry.orcid);
    }, [entry]);

    // Manual retry function
    const retryVerification = useCallback(() => {
        if (!isPersonEntry(entry) || !entry.orcid?.trim()) return;
        clearError();
        setRetryTrigger((prev) => prev + 1);
    }, [entry, clearError]);

    /**
     * Apply selected pending data to the entry
     */
    const applyPendingData = useCallback(
        (selected: SelectedPendingData) => {
            if (!isPersonEntry(entry) || !pendingOrcidData) return;

            const updatedEntry = { ...entry } as T;

            // Apply selected affiliations
            if (selected.affiliations.length > 0) {
                // Start with a copy of existing affiliations
                const mergedAffiliations = [...entry.affiliations];

                for (const pending of selected.affiliations) {
                    const tag: AffiliationTag = {
                        value: pending.resolvedName ?? pending.value,
                        rorId: pending.rorId ?? null,
                    };

                    if (pending.status === 'different') {
                        // Replace the existing affiliation that this differs from
                        const idx = mergedAffiliations.findIndex(
                            (a) =>
                                (pending.rorId && a.rorId === pending.rorId) ||
                                (pending.existingValue && a.value.toLowerCase().trim() === pending.existingValue.toLowerCase().trim()),
                        );
                        if (idx !== -1) {
                            mergedAffiliations[idx] = tag;
                        } else {
                            mergedAffiliations.push(tag);
                        }
                    } else {
                        // New affiliation — append
                        mergedAffiliations.push(tag);
                    }
                }

                updatedEntry.affiliations = mergedAffiliations;
                updatedEntry.affiliationsInput = updatedEntry.affiliations.map((a) => a.value).join(', ');
            }

            // Apply name corrections
            if (selected.applyFirstName && pendingOrcidData.firstNameDiff) {
                (updatedEntry as unknown as PersonEntry).firstName = pendingOrcidData.firstNameDiff.orcid;
            }
            if (selected.applyLastName && pendingOrcidData.lastNameDiff) {
                (updatedEntry as unknown as PersonEntry).lastName = pendingOrcidData.lastNameDiff.orcid;
            }

            // Apply email
            if (selected.applyEmail && pendingOrcidData.emailSuggestion && 'email' in updatedEntry) {
                (updatedEntry as T & { email: string }).email = pendingOrcidData.emailSuggestion;
            }

            onEntryChange(updatedEntry);
            setPendingOrcidData(null);
        },
        [entry, pendingOrcidData, onEntryChange],
    );

    /**
     * Handle ORCID selection (from search dialog or suggestions)
     */
    const handleOrcidSelect = useCallback(
        async (orcid: string) => {
            if (!isPersonEntry(entry)) return;

            setVerificationError(null);
            setIsVerifying(true);

            try {
                const response = await OrcidService.fetchOrcidRecord(orcid);

                if (!response.success || !response.data) {
                    setVerificationError(response.error || 'Failed to fetch ORCID data');
                    setIsVerifying(false);
                    return;
                }

                // Update ORCID field on the entry for selection
                const entryWithOrcid = { ...entry, orcid } as T & PersonEntry;
                const { updatedEntry, pendingData } = await processOrcidData(entryWithOrcid, response.data, includeEmail);
                setPendingOrcidData(pendingData);
                onEntryChange(updatedEntry);
            } catch (error) {
                console.error('ORCID fetch error:', error);
                setVerificationError('Failed to fetch complete ORCID data');
            } finally {
                setIsVerifying(false);
            }
        },
        [entry, onEntryChange, includeEmail],
    );

    /**
     * Auto-suggest ORCIDs based on name and affiliations
     * Only triggers after user interaction to prevent unwanted searches on load
     */
    useEffect(() => {
        if (!hasUserInteracted) {
            return;
        }

        const searchForOrcid = async () => {
            // Only search if person type, has name, and no ORCID yet
            if (!isPersonEntry(entry)) {
                setOrcidSuggestions([]);
                setShowSuggestions(false);
                return;
            }

            const personEntry = entry;
            if (!personEntry.firstName?.trim() || !personEntry.lastName?.trim() || personEntry.orcid?.trim() || personEntry.orcidVerified) {
                setOrcidSuggestions([]);
                setShowSuggestions(false);
                return;
            }

            setIsLoadingSuggestions(true);
            setShowSuggestions(true);

            try {
                // Build search query: "FirstName LastName"
                let searchQuery = `${personEntry.firstName.trim()} ${personEntry.lastName.trim()}`;

                // Add first affiliation if available to refine search
                if (personEntry.affiliations.length > 0 && personEntry.affiliations[0].value) {
                    searchQuery += ` ${personEntry.affiliations[0].value}`;
                }

                const response = await OrcidService.searchOrcid(searchQuery);

                if (response.success && response.data) {
                    // Limit to top 5 suggestions
                    setOrcidSuggestions(response.data.results.slice(0, 5));
                } else {
                    setOrcidSuggestions([]);
                }
            } catch (error) {
                console.error('ORCID suggestion error:', error);
                setOrcidSuggestions([]);
            } finally {
                setIsLoadingSuggestions(false);
            }
        };

        // Debounce: Wait 800ms after user stops typing
        const timeoutId = setTimeout(searchForOrcid, 800);

        return () => clearTimeout(timeoutId);
    }, [entry, hasUserInteracted]);

    /**
     * Auto-verify when a valid ORCID is entered
     */
    useEffect(() => {
        const autoVerifyOrcid = async () => {
            // Only auto-verify if person type
            if (!isPersonEntry(entry)) {
                return;
            }

            const personEntry = entry;

            // Only auto-verify if:
            // 1. ORCID has valid format
            // 2. Not already verified
            // 3. Not currently verifying
            if (!personEntry.orcid?.trim() || !OrcidService.isValidFormat(personEntry.orcid) || personEntry.orcidVerified || isVerifying) {
                return;
            }

            // Early checksum validation (offline) - provides instant feedback without network round-trip.
            // Backend also validates for security, but frontend validation improves UX.
            if (!OrcidService.validateChecksum(personEntry.orcid)) {
                setVerificationError('Invalid ORCID checksum');
                setErrorType('checksum');
                setCanRetry(false);
                return;
            }

            setIsVerifying(true);
            setVerificationError(null);
            setErrorType(null);
            setCanRetry(false);

            try {
                // Validate ORCID exists
                const validationResponse = await OrcidService.validateOrcid(personEntry.orcid);

                if (!validationResponse.success) {
                    setVerificationError('Network error - please check connection');
                    setErrorType('network');
                    setCanRetry(true);
                    setIsVerifying(false);
                    return;
                }

                const validationData = validationResponse.data;

                // Handle different error types from backend
                if (validationData?.errorType === 'not_found') {
                    setVerificationError('ORCID not found');
                    setErrorType('not_found');
                    setCanRetry(false); // No retry for confirmed 404
                    setIsVerifying(false);
                    return;
                }

                // Permanent validation errors - no retry
                if (validationData?.errorType === 'checksum' || validationData?.errorType === 'format') {
                    setVerificationError(validationData.errorType === 'checksum' ? 'Invalid ORCID checksum' : 'Invalid ORCID format');
                    setErrorType(validationData.errorType);
                    setCanRetry(false);
                    setIsVerifying(false);
                    return;
                }

                if (validationData?.errorType === 'timeout' || validationData?.errorType === 'api_error') {
                    setVerificationError('ORCID service temporarily unavailable');
                    setErrorType(validationData.errorType);
                    setCanRetry(true);
                    setIsVerifying(false);
                    return;
                }

                if (!validationData?.exists) {
                    setVerificationError('Could not verify ORCID');
                    setErrorType('unknown');
                    setCanRetry(true);
                    setIsVerifying(false);
                    return;
                }

                // Fetch full ORCID record
                const response = await OrcidService.fetchOrcidRecord(personEntry.orcid);

                if (!response.success || !response.data) {
                    setVerificationError('Failed to fetch ORCID data');
                    setErrorType('api_error');
                    setCanRetry(true);
                    setIsVerifying(false);
                    return;
                }

                const { updatedEntry, pendingData } = await processOrcidData(personEntry, response.data, includeEmail);
                setPendingOrcidData(pendingData);
                onEntryChange(updatedEntry);
            } catch (error) {
                console.error('Auto-verify ORCID error:', error);
                setVerificationError('Failed to verify ORCID');
                setErrorType('network');
                setCanRetry(true);
            } finally {
                setIsVerifying(false);
            }
        };

        // Debounce: Wait 500ms after ORCID changes
        const timeoutId = setTimeout(autoVerifyOrcid, 500);

        return () => clearTimeout(timeoutId);
    }, [entry, isVerifying, onEntryChange, includeEmail, retryTrigger]);

    return {
        isVerifying,
        verificationError,
        clearError,
        orcidSuggestions,
        isLoadingSuggestions,
        showSuggestions,
        hideSuggestions,
        handleOrcidSelect,
        canRetry,
        retryVerification,
        errorType,
        isFormatValid,
        pendingOrcidData,
        clearPendingOrcidData,
        applyPendingData,
    };
}

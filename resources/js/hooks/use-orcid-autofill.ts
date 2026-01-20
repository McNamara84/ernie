/**
 * useOrcidAutofill Hook
 *
 * Shared ORCID verification, auto-fill, and suggestion logic for Authors and Contributors.
 * Eliminates ~150 lines of duplicated code between author-item.tsx and contributor-item.tsx.
 *
 * Features:
 * - Auto-verify ORCID when a valid format is entered
 * - Auto-suggest ORCIDs based on name and affiliations
 * - Fetch and merge ORCID data (name, affiliations)
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
}

/**
 * Extract employment affiliations from ORCID record
 */
function extractEmploymentAffiliations(
    orcidAffiliations: OrcidAffiliation[],
    existingAffiliations: AffiliationTag[],
): { affiliations: AffiliationTag[]; affiliationsInput: string } | null {
    const employmentAffiliations = orcidAffiliations
        .filter((aff) => aff.type === 'employment' && aff.name)
        .map((aff) => ({
            value: aff.name!,
            rorId: null,
        }));

    if (employmentAffiliations.length === 0) {
        return null;
    }

    const existingValues = new Set(existingAffiliations.map((a) => a.value));
    const newAffiliations = employmentAffiliations.filter((a) => !existingValues.has(a.value));

    if (newAffiliations.length === 0) {
        return null;
    }

    const mergedAffiliations = [...existingAffiliations, ...(newAffiliations as AffiliationTag[])];

    return {
        affiliations: mergedAffiliations,
        affiliationsInput: mergedAffiliations.map((a) => a.value).join(', '),
    };
}

/**
 * Type guard to check if entry is a PersonEntry
 */
function isPersonEntry<T extends BaseEntry>(entry: T): entry is T & PersonEntry {
    return entry.type === 'person';
}

/**
 * useOrcidAutofill - Shared hook for ORCID verification and auto-fill
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

    // Track retry trigger
    const [retryTrigger, setRetryTrigger] = useState(0);

    const clearError = useCallback(() => {
        setVerificationError(null);
        setErrorType(null);
        setCanRetry(false);
    }, []);
    const hideSuggestions = useCallback(() => setShowSuggestions(false), []);

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

                const data = response.data;

                // Build updated entry with ORCID data
                const updatedEntry = {
                    ...entry,
                    orcid,
                    firstName: data.firstName || entry.firstName,
                    lastName: data.lastName || entry.lastName,
                    orcidVerified: true,
                    orcidVerifiedAt: new Date().toISOString(),
                } as T;

                // Include email for authors if available
                if (includeEmail && data.emails.length > 0 && 'email' in entry && !entry.email) {
                    (updatedEntry as T & { email: string }).email = data.emails[0];
                }

                // Merge affiliations from ORCID
                const affiliationResult = extractEmploymentAffiliations(data.affiliations, entry.affiliations);
                if (affiliationResult) {
                    updatedEntry.affiliations = affiliationResult.affiliations;
                    updatedEntry.affiliationsInput = affiliationResult.affiliationsInput;
                }

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

                const data = response.data;

                // Build updated entry
                const updatedEntry = {
                    ...personEntry,
                    firstName: data.firstName || personEntry.firstName,
                    lastName: data.lastName || personEntry.lastName,
                    orcidVerified: true,
                    orcidVerifiedAt: new Date().toISOString(),
                } as T;

                // Include email for authors if available
                if (includeEmail && data.emails.length > 0 && 'email' in personEntry && !personEntry.email) {
                    (updatedEntry as T & { email: string }).email = data.emails[0];
                }

                // Merge affiliations from ORCID
                const affiliationResult = extractEmploymentAffiliations(data.affiliations, personEntry.affiliations);
                if (affiliationResult) {
                    updatedEntry.affiliations = affiliationResult.affiliations;
                    updatedEntry.affiliationsInput = affiliationResult.affiliationsInput;
                }

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
    };
}

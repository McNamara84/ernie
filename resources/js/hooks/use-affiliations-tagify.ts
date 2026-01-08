/**
 * useAffiliationsTagify Hook
 *
 * Shared Tagify settings and utility functions for affiliations input.
 * Eliminates duplicated affiliation display logic between author-item.tsx and contributor-item.tsx.
 */

import type { TagData, TagifySettings } from '@yaireo/tagify';
import { useMemo } from 'react';

import type { AffiliationSuggestion, AffiliationTag } from '@/types/affiliations';

/**
 * Configuration for the hook
 */
export interface UseAffiliationsTagifyConfig {
    /** Available affiliation suggestions from API */
    affiliationSuggestions: AffiliationSuggestion[];
    /** Current affiliations on the entry */
    affiliations: AffiliationTag[];
    /** Unique ID prefix for accessibility */
    idPrefix: string;
}

/**
 * Extracted affiliation with ROR ID for display
 */
export interface AffiliationWithRorId {
    value: string;
    rorId: string;
}

/**
 * Hook return value
 */
export interface UseAffiliationsTagifyReturn {
    /** Tagify settings for the affiliations input */
    tagifySettings: Partial<TagifySettings<TagData>>;
    /** Affiliations that have ROR IDs (for badge display) */
    affiliationsWithRorId: AffiliationWithRorId[];
    /** Accessibility ID for affiliations description */
    affiliationsDescriptionId: string | undefined;
}

/**
 * Extract unique affiliations that have ROR IDs
 */
function extractAffiliationsWithRorId(affiliations: AffiliationTag[]): AffiliationWithRorId[] {
    const seen = new Set<string>();

    return affiliations.reduce<AffiliationWithRorId[]>((accumulator, affiliation) => {
        const value = affiliation.value.trim();
        const rorId = typeof affiliation.rorId === 'string' ? affiliation.rorId.trim() : '';

        if (!value || !rorId || seen.has(rorId)) {
            return accumulator;
        }

        seen.add(rorId);
        accumulator.push({ value, rorId });
        return accumulator;
    }, []);
}

/**
 * useAffiliationsTagify - Shared hook for affiliation input configuration
 */
export function useAffiliationsTagify({ affiliationSuggestions, affiliations, idPrefix }: UseAffiliationsTagifyConfig): UseAffiliationsTagifyReturn {
    // Tagify settings for affiliations autocomplete
    const tagifySettings = useMemo<Partial<TagifySettings<TagData>>>(() => {
        const whitelist = affiliationSuggestions.map((suggestion) => ({
            value: suggestion.value,
            rorId: suggestion.rorId,
            searchTerms: suggestion.searchTerms,
        }));

        return {
            whitelist,
            dropdown: {
                enabled: whitelist.length > 0 ? 1 : 0,
                maxItems: 20,
                closeOnSelect: true,
                searchKeys: ['value', 'searchTerms'],
            },
        };
    }, [affiliationSuggestions]);

    // Extract affiliations with ROR IDs for badge display
    const affiliationsWithRorId = useMemo(() => extractAffiliationsWithRorId(affiliations), [affiliations]);

    // Accessibility ID
    const affiliationsDescriptionId = affiliationsWithRorId.length > 0 ? `${idPrefix}-affiliations-ror-description` : undefined;

    return {
        tagifySettings,
        affiliationsWithRorId,
        affiliationsDescriptionId,
    };
}

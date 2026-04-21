import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';

import { apiRequest } from '@/lib/api-client';
import { apiEndpoints, queryKeys } from '@/lib/query-keys';
import type { AffiliationSuggestion } from '@/types/affiliations';

interface UseRorAffiliationsResult {
    suggestions: AffiliationSuggestion[];
    isLoading: boolean;
    error: Error | null;
}

const normalizeSuggestion = (input: unknown): AffiliationSuggestion | null => {
    if (!input || typeof input !== 'object') {
        return null;
    }

    const raw = input as Record<string, unknown>;

    // Map new JSON structure (prefLabel, rorId, otherLabel) to internal structure (value, rorId, searchTerms)
    const prefLabel = typeof raw.prefLabel === 'string' ? raw.prefLabel.trim() : '';
    const rorId = typeof raw.rorId === 'string' ? raw.rorId.trim() : '';

    if (!prefLabel || !rorId) {
        return null;
    }

    const otherLabel = Array.isArray(raw.otherLabel)
        ? raw.otherLabel.map((term) => (typeof term === 'string' ? term.trim() : '')).filter((term): term is string => Boolean(term))
        : [prefLabel];

    return {
        value: prefLabel,
        rorId,
        searchTerms: otherLabel,
    };
};

/**
 * Fetch and normalise ROR affiliations from the backend.
 *
 * Exported so that it can be reused for prefetching (e.g. on sidebar hover)
 * and inside unit tests.
 */
export async function fetchRorAffiliations(signal?: AbortSignal): Promise<AffiliationSuggestion[]> {
    const payload = await apiRequest<unknown>(apiEndpoints.rorAffiliations, { signal });

    if (!Array.isArray(payload)) {
        return [];
    }

    return payload
        .map((item) => normalizeSuggestion(item))
        .filter((item): item is AffiliationSuggestion => item !== null);
}

/**
 * Hook exposing the full ROR affiliation vocabulary.
 *
 * The underlying list changes rarely, so the cache entry is kept fresh for
 * 30 minutes to avoid redundant requests while the curator navigates between
 * pages within the same client session.
 */
export function useRorAffiliations(): UseRorAffiliationsResult {
    const { data, isLoading, error } = useQuery({
        queryKey: queryKeys.ror.all(),
        queryFn: ({ signal }) => fetchRorAffiliations(signal),
        staleTime: 30 * 60_000,
    });

    return useMemo(
        () => ({
            suggestions: data ?? [],
            isLoading,
            error: (error as Error | null) ?? null,
        }),
        [data, isLoading, error],
    );
}

export type { AffiliationSuggestion };

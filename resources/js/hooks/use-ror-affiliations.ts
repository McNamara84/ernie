import { useEffect, useMemo, useState } from 'react';

import { withBasePath } from '@/lib/base-path';
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
        ? raw.otherLabel
              .map((term) => (typeof term === 'string' ? term.trim() : ''))
              .filter((term): term is string => Boolean(term))
        : [prefLabel];

    return {
        value: prefLabel,
        rorId,
        searchTerms: otherLabel,
    };
};

export function useRorAffiliations(): UseRorAffiliationsResult {
    const [suggestions, setSuggestions] = useState<AffiliationSuggestion[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<Error | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        let isMounted = true;

        const fetchSuggestions = async () => {
            setIsLoading(true);
            setError(null);

            try {
                const response = await fetch(withBasePath('/api/v1/ror-affiliations'), {
                    headers: {
                        Accept: 'application/json',
                    },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error(`Failed to load ROR affiliations (${response.status})`);
                }

                const payload = (await response.json()) as unknown;
                
                const parsed = Array.isArray(payload)
                    ? payload
                          .map((item) => normalizeSuggestion(item))
                          .filter((item): item is AffiliationSuggestion => Boolean(item))
                    : [];

                if (!isMounted) {
                    return;
                }

                setSuggestions(parsed);
            } catch (caught) {
                if (controller.signal.aborted) {
                    return;
                }

                const normalisedError = caught instanceof Error ? caught : new Error(String(caught));

                if (isMounted) {
                    setError(normalisedError);
                    setSuggestions([]);
                }
            } finally {
                if (isMounted) {
                    setIsLoading(false);
                }
            }
        };

        fetchSuggestions();

        return () => {
            isMounted = false;
            controller.abort();
        };
    }, []);

    return useMemo(
        () => ({
            suggestions,
            isLoading,
            error,
        }),
        [suggestions, isLoading, error],
    );
}

export type { AffiliationSuggestion };

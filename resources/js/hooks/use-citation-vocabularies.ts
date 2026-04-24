import { useEffect, useState } from 'react';

import { apiRequest } from '@/lib/api-client';

export interface CitationVocabularies {
    resourceTypes: { value: string; label: string }[];
    relationTypes: { id: number; label: string }[];
    contributorTypes: { value: string; label: string }[];
}

const EMPTY: CitationVocabularies = {
    resourceTypes: [],
    relationTypes: [],
    contributorTypes: [],
};

// Module-level cache so all modal mounts share a single fetch.
let cached: CitationVocabularies | null = null;
let inflight: Promise<CitationVocabularies> | null = null;

/**
 * Loads the vocabularies required by the Citation Manager UI
 * (`/related-items/vocabularies`) and caches them for the session.
 */
export function useCitationVocabularies(): {
    vocabularies: CitationVocabularies;
    isLoading: boolean;
    error: string | null;
} {
    const [vocab, setVocab] = useState<CitationVocabularies>(cached ?? EMPTY);
    const [isLoading, setIsLoading] = useState(cached === null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (cached !== null) {
            setVocab(cached);
            setIsLoading(false);
            return;
        }
        let cancelled = false;
        if (inflight === null) {
            inflight = apiRequest<CitationVocabularies>('/related-items/vocabularies')
                .then((data) => {
                    cached = data;
                    return data;
                })
                .finally(() => {
                    inflight = null;
                });
        }
        inflight
            .then((data) => {
                if (!cancelled) {
                    setVocab(data);
                    setIsLoading(false);
                }
            })
            .catch((err: unknown) => {
                if (!cancelled) {
                    setError(err instanceof Error ? err.message : 'Failed to load vocabularies');
                    setIsLoading(false);
                }
            });
        return () => {
            cancelled = true;
        };
    }, []);

    return { vocabularies: vocab, isLoading, error };
}

import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError, apiRequest } from '@/lib/api-client';
import type { CitationLookupResult } from '@/types/related-item';

interface UseCitationLookupOptions {
    /** Debounce in ms before firing the lookup. Defaults to 500. */
    debounceMs?: number;
}

interface UseCitationLookupReturn {
    lookup: (doi: string) => void;
    reset: () => void;
    result: CitationLookupResult | null;
    isLoading: boolean;
    error: string | null;
}

/**
 * Debounced DOI → citation lookup hook that calls `GET /api/v1/citation-lookup`.
 *
 * Results are cached per-DOI in an in-memory session map so that repeated
 * lookups of the same identifier do not hit the rate-limited endpoint twice.
 */
export function useCitationLookup(options: UseCitationLookupOptions = {}): UseCitationLookupReturn {
    const debounceMs = options.debounceMs ?? 500;
    const [result, setResult] = useState<CitationLookupResult | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const cacheRef = useRef<Map<string, CitationLookupResult>>(new Map());
    const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => () => {
        if (timeoutRef.current) clearTimeout(timeoutRef.current);
        abortRef.current?.abort();
    }, []);

    const reset = useCallback(() => {
        if (timeoutRef.current) clearTimeout(timeoutRef.current);
        abortRef.current?.abort();
        setResult(null);
        setError(null);
        setIsLoading(false);
    }, []);

    const lookup = useCallback(
        (doi: string) => {
            const normalized = doi.trim();
            if (timeoutRef.current) clearTimeout(timeoutRef.current);
            abortRef.current?.abort();

            if (normalized === '') {
                setResult(null);
                setError(null);
                setIsLoading(false);
                return;
            }

            // Cache hit → return synchronously
            const cached = cacheRef.current.get(normalized);
            if (cached) {
                setResult(cached);
                setError(null);
                setIsLoading(false);
                return;
            }

            setIsLoading(true);
            setError(null);

            timeoutRef.current = setTimeout(() => {
                const controller = new AbortController();
                abortRef.current = controller;

                apiRequest<CitationLookupResult>(
                    `/api/v1/citation-lookup?doi=${encodeURIComponent(normalized)}`,
                    { signal: controller.signal },
                )
                    .then((data) => {
                        cacheRef.current.set(normalized, data);
                        setResult(data);
                        setIsLoading(false);
                    })
                    .catch((err: unknown) => {
                        if (err instanceof DOMException && err.name === 'AbortError') return;
                        if (err instanceof ApiError) {
                            setError(
                                err.status === 429
                                    ? 'Too many lookup requests — please wait a moment.'
                                    : err.message,
                            );
                        } else if (err instanceof Error) {
                            setError(err.message);
                        } else {
                            setError('Citation lookup failed');
                        }
                        setResult(null);
                        setIsLoading(false);
                    });
            }, debounceMs);
        },
        [debounceMs],
    );

    return { lookup, reset, result, isLoading, error };
}

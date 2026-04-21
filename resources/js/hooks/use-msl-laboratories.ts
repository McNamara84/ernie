import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '@/lib/api-client';
import { apiEndpoints, queryKeys } from '@/lib/query-keys';
import type { MSLLaboratory } from '@/types';

interface UseMSLLaboratoriesReturn {
    laboratories: MSLLaboratory[] | null;
    isLoading: boolean;
    error: string | null;
    refetch: () => void;
}

/**
 * Fetch the MSL vocabulary URL and then the laboratories payload.
 *
 * Exported for prefetching and unit testing.
 */
export async function fetchMslLaboratories(signal?: AbortSignal): Promise<MSLLaboratory[]> {
    const { url } = await apiRequest<{ url: string }>(apiEndpoints.mslVocabularyUrl, { signal });

    if (typeof url !== 'string' || url.length === 0) {
        throw new Error('Invalid vocabulary URL received from backend');
    }

    // The vocabulary URL points to an external resource (Utrecht University).
    // Use a plain `fetch` here with only simple headers so the request is
    // treated as a CORS-simple GET and does not trigger a preflight that the
    // third-party host would reject.
    const response = await fetch(url, {
        signal,
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`Failed to fetch laboratories: ${response.status} ${response.statusText}`);
    }

    const data = (await response.json()) as unknown;

    if (!Array.isArray(data)) {
        throw new Error('Invalid data format: expected an array');
    }

    return data as MSLLaboratory[];
}

/**
 * Custom hook to fetch and manage MSL (Multi-Scale Laboratories) data
 * from the Utrecht University MSL Vocabularies repository.
 *
 * The vocabulary URL is fetched from the backend to ensure consistency and
 * to avoid hardcoding the external URL in the frontend bundle.
 */
export function useMSLLaboratories(): UseMSLLaboratoriesReturn {
    const { data, isLoading, error, refetch } = useQuery({
        queryKey: queryKeys.msl.laboratories(),
        queryFn: ({ signal }) => fetchMslLaboratories(signal),
        // Laboratories change rarely; cache aggressively to avoid cross-origin
        // requests on every Editor visit.
        staleTime: 30 * 60_000,
    });

    return {
        laboratories: data ?? null,
        isLoading,
        error: error instanceof Error ? error.message : error ? String(error) : null,
        refetch: () => {
            void refetch();
        },
    };
}

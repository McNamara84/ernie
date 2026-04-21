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
    // We go through `apiRequest` to get the shared error/retry semantics even
    // though the request is cross-origin.
    const data = await apiRequest<unknown>(url, { signal });

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

import { useQuery } from '@tanstack/react-query';

import { ApiError, apiRequest } from '@/lib/api-client';
import { apiEndpoints, queryKeys } from '@/lib/query-keys';
import { mslLaboratoriesResponseSchema } from '@/schemas/msl-laboratory.schema';
import type { MSLLaboratoriesResponse, MSLLaboratoryVocabularyEntry } from '@/types';

interface UseMSLLaboratoriesOptions {
    enabled?: boolean;
}

interface UseMSLLaboratoriesReturn {
    laboratories: MSLLaboratoryVocabularyEntry[] | null;
    version: string | null;
    lastUpdated: string | null;
    isLoading: boolean;
    isUnavailable: boolean;
    error: string | null;
    refetch: () => void;
}

/**
 * Fetch and validate the locally managed MSL laboratories vocabulary.
 *
 * Exported for prefetching and unit testing.
 */
export async function fetchMslLaboratories(signal?: AbortSignal): Promise<MSLLaboratoriesResponse> {
    const payload = await apiRequest<unknown>(apiEndpoints.mslLaboratories, { signal });
    const result = mslLaboratoriesResponseSchema.safeParse(payload);

    if (!result.success) {
        const firstIssue = result.error.issues[0];
        const detail = firstIssue ? `${firstIssue.path.join('.') || 'response'}: ${firstIssue.message}` : 'unknown validation error';
        throw new Error(`Invalid MSL laboratories response: ${detail}`);
    }

    return result.data;
}

/**
 * Load the MSL laboratory vocabulary from ERNIE's local endpoint.
 *
 * A 404 is an expected unavailable state (disabled in settings or not yet
 * downloaded) and is kept separate from operational errors.
 */
export function useMSLLaboratories({ enabled = true }: UseMSLLaboratoriesOptions = {}): UseMSLLaboratoriesReturn {
    const { data, isLoading, error, refetch } = useQuery({
        queryKey: queryKeys.msl.laboratories(),
        queryFn: ({ signal }) => fetchMslLaboratories(signal),
        enabled,
        staleTime: 30 * 60_000,
        gcTime: 30 * 60_000,
    });

    const isUnavailable = !enabled || (error instanceof ApiError && error.status === 404);

    return {
        laboratories: data?.data ?? null,
        version: data?.version ?? null,
        lastUpdated: data?.lastUpdated ?? null,
        isLoading: enabled && isLoading,
        isUnavailable,
        error: !enabled || isUnavailable ? null : error instanceof Error ? error.message : error ? String(error) : null,
        refetch: () => {
            if (enabled) {
                void refetch();
            }
        },
    };
}

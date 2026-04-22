import { useQuery } from '@tanstack/react-query';

import { ApiError, apiRequest } from '@/lib/api-client';
import { apiEndpoints, queryKeys } from '@/lib/query-keys';

/**
 * Instrument record from the PID4INST / b2inst registry.
 */
export interface Pid4instInstrument {
    id: string;
    pid: string;
    pidType: string;
    name: string;
    description: string;
    landingPage: string;
    owners: string[];
    manufacturers: string[];
    model: string | null;
    instrumentTypes: string[];
    measuredVariables: string[];
}

interface UsePid4instInstrumentsReturn {
    instruments: Pid4instInstrument[] | null;
    isLoading: boolean;
    error: string | null;
    refetch: () => void;
}

const DEFAULT_404_MESSAGE = 'Instrument registry not yet downloaded. An administrator must first download it in Settings.';

/**
 * Fetch PID4INST instruments from the backend vocabulary endpoint.
 *
 * Translates the 404 "registry not downloaded" case into a user-friendly
 * message; all other errors bubble up with the original status code so that
 * the caller can render appropriate feedback.
 *
 * Exported for prefetching and unit testing.
 */
export async function fetchPid4instInstruments(signal?: AbortSignal): Promise<Pid4instInstrument[]> {
    try {
        const json = await apiRequest<{ data?: Pid4instInstrument[] }>(apiEndpoints.pid4instInstruments, { signal });

        if (!json || !Array.isArray(json.data)) {
            throw new Error('Invalid data format: expected { data: [...] }');
        }

        return json.data;
    } catch (err) {
        if (err instanceof ApiError && err.status === 404) {
            const backendMessage =
                err.body && typeof err.body === 'object' && 'error' in err.body && typeof (err.body as { error?: unknown }).error === 'string'
                    ? ((err.body as { error: string }).error)
                    : null;
            throw new Error(backendMessage ?? DEFAULT_404_MESSAGE, { cause: err });
        }
        throw err;
    }
}

/**
 * Custom hook to fetch PID4INST instruments from the locally cached b2inst data.
 *
 * The data is fetched from the backend vocabulary endpoint which reads from a
 * local JSON file (downloaded via `php artisan get-pid4inst-instruments`).
 */
export function usePid4instInstruments(): UsePid4instInstrumentsReturn {
    const { data, isLoading, error, refetch } = useQuery({
        queryKey: queryKeys.pid4inst.instruments(),
        queryFn: ({ signal }) => fetchPid4instInstruments(signal),
        staleTime: 30 * 60_000,
        // Keep the cache alive for the full freshness window even when all
        // consumers unmount; the shorter global `gcTime` default would
        // otherwise evict it before `staleTime` elapses.
        gcTime: 30 * 60_000,
    });

    return {
        instruments: data ?? null,
        isLoading,
        error: error instanceof Error ? error.message : error ? String(error) : null,
        refetch: () => {
            void refetch();
        },
    };
}

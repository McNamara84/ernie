import { useEffect, useState } from 'react';

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
    model: string;
    instrumentTypes: string[];
    measuredVariables: string[];
}

interface UsePid4instInstrumentsReturn {
    instruments: Pid4instInstrument[] | null;
    isLoading: boolean;
    error: string | null;
    refetch: () => void;
}

/**
 * Custom hook to fetch PID4INST instruments from the locally cached b2inst data.
 * The data is fetched from the backend vocabulary endpoint which reads from a
 * local JSON file (downloaded via `php artisan get-pid4inst-instruments`).
 *
 * @returns Object containing instruments data, loading state, error, and refetch function
 */
export function usePid4instInstruments(): UsePid4instInstrumentsReturn {
    const [instruments, setInstruments] = useState<Pid4instInstrument[] | null>(null);
    const [isLoading, setIsLoading] = useState<boolean>(false);
    const [error, setError] = useState<string | null>(null);
    const [fetchTrigger, setFetchTrigger] = useState<number>(0);

    useEffect(() => {
        const fetchInstruments = async () => {
            setIsLoading(true);
            setError(null);

            try {
                const response = await fetch('/vocabularies/pid4inst-instruments');

                if (!response.ok) {
                    if (response.status === 404) {
                        // Try to read the specific error message from the backend
                        try {
                            const errorBody = (await response.json()) as { error?: string };
                            if (errorBody.error) {
                                throw new Error(errorBody.error);
                            }
                        } catch {
                            // If JSON parsing fails, fall through to default message
                        }
                        throw new Error('Instrument registry not yet downloaded. An administrator must first download it in Settings.');
                    }
                    throw new Error(`Failed to fetch instruments: ${response.status} ${response.statusText}`);
                }

                const json = (await response.json()) as { data: Pid4instInstrument[] };

                if (!json.data || !Array.isArray(json.data)) {
                    throw new Error('Invalid data format: expected { data: [...] }');
                }

                setInstruments(json.data);
            } catch (err) {
                const errorMessage = err instanceof Error ? err.message : 'Unknown error occurred';
                setError(errorMessage);
                console.error('Error fetching PID4INST instruments:', err);
            } finally {
                setIsLoading(false);
            }
        };

        void fetchInstruments();
    }, [fetchTrigger]);

    const refetch = () => {
        setFetchTrigger((prev) => prev + 1);
    };

    return {
        instruments,
        isLoading,
        error,
        refetch,
    };
}

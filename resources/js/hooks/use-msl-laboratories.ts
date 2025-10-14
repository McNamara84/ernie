import { useEffect, useState } from 'react';

import type { MSLLaboratory } from '@/types';

interface UseMSLLaboratoriesReturn {
    laboratories: MSLLaboratory[] | null;
    isLoading: boolean;
    error: string | null;
    refetch: () => void;
}

/**
 * Custom hook to fetch and manage MSL (Multi-Scale Laboratories) data
 * from the Utrecht University MSL Vocabularies repository.
 * The vocabulary URL is fetched from the backend to ensure consistency.
 *
 * @returns {UseMSLLaboratoriesReturn} Object containing laboratories data, loading state, error, and refetch function
 */
export function useMSLLaboratories(): UseMSLLaboratoriesReturn {
    const [laboratories, setLaboratories] = useState<MSLLaboratory[] | null>(null);
    const [isLoading, setIsLoading] = useState<boolean>(false);
    const [error, setError] = useState<string | null>(null);
    const [fetchTrigger, setFetchTrigger] = useState<number>(0);

    useEffect(() => {
        const fetchLaboratories = async () => {
            setIsLoading(true);
            setError(null);

            try {
                // First, get the vocabulary URL from the backend to ensure consistency
                const urlResponse = await fetch('/api/v1/msl-vocabulary-url');
                if (!urlResponse.ok) {
                    throw new Error(`Failed to fetch vocabulary URL: ${urlResponse.status}`);
                }
                const { url: vocabularyUrl } = (await urlResponse.json()) as { url: string };

                // Then fetch the laboratories from the vocabulary URL
                const response = await fetch(vocabularyUrl);

                if (!response.ok) {
                    throw new Error(`Failed to fetch laboratories: ${response.status} ${response.statusText}`);
                }

                const data = (await response.json()) as MSLLaboratory[];

                // Validate data structure
                if (!Array.isArray(data)) {
                    throw new Error('Invalid data format: expected an array');
                }

                setLaboratories(data);
            } catch (err) {
                const errorMessage = err instanceof Error ? err.message : 'Unknown error occurred';
                setError(errorMessage);
                console.error('Error fetching MSL laboratories:', err);
            } finally {
                setIsLoading(false);
            }
        };

        void fetchLaboratories();
    }, [fetchTrigger]);

    const refetch = () => {
        setFetchTrigger((prev) => prev + 1);
    };

    return {
        laboratories,
        isLoading,
        error,
        refetch,
    };
}

import { useQuery } from '@tanstack/react-query';

import { useDebounce } from '@/hooks/use-debounce';
import { ApiError, apiRequest } from '@/lib/api-client';
import { apiEndpoints, queryKeys } from '@/lib/query-keys';
import type { KeywordSuggestion } from '@/types/portal';

interface KeywordSuggestionResponse {
    data: KeywordSuggestion[];
}

export function usePortalKeywordSuggestions(input: string) {
    const normalizedInput = input.trim();
    const debouncedInput = useDebounce(normalizedInput, 300);

    return useQuery({
        queryKey: queryKeys.portal.keywordSuggestions(debouncedInput.toLowerCase()),
        queryFn: ({ signal }) =>
            apiRequest<KeywordSuggestionResponse>(
                `${apiEndpoints.portalKeywordSuggestions}?${new URLSearchParams({ q: debouncedInput }).toString()}`,
                { signal },
            ),
        enabled: debouncedInput.length >= 2,
        select: (response) => response.data,
        staleTime: 5 * 60_000,
        gcTime: 15 * 60_000,
        retry: (failureCount, error) => {
            if (error instanceof ApiError && [400, 422, 429].includes(error.status)) {
                return false;
            }

            return failureCount < 2;
        },
    });
}

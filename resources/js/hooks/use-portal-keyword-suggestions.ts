import { useQuery } from '@tanstack/react-query';

import { useDebounce } from '@/hooks/use-debounce';
import { ApiError, apiRequest } from '@/lib/api-client';
import { queryKeys } from '@/lib/query-keys';
import type { KeywordSuggestion, PortalBasePath } from '@/types/portal';

interface KeywordSuggestionResponse {
    data: KeywordSuggestion[];
}

export function usePortalKeywordSuggestions(input: string, basePath: PortalBasePath = '/doi-search') {
    const normalizedInput = input.trim();
    const debouncedInput = useDebounce(normalizedInput, 300);

    return useQuery({
        queryKey: queryKeys.portal.keywordSuggestions(basePath, debouncedInput.toLowerCase()),
        queryFn: ({ signal }) => {
            const query = new URLSearchParams({ q: debouncedInput }).toString();

            return apiRequest<KeywordSuggestionResponse>(`${basePath}/free-keyword-suggestions?${query}`, { signal });
        },
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

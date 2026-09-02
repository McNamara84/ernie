import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { ApiError, apiRequest } from '@/lib/api-client';
import { buildPortalMapUrl } from '@/lib/portal-filter-url';
import { queryKeys } from '@/lib/query-keys';
import type { PortalBasePath, PortalFilters, PortalMapResponse, PortalMapViewport } from '@/types/portal';

export function usePortalMapData(
    filters: PortalFilters,
    viewport: PortalMapViewport | null,
    includeExtent: boolean,
    basePath: PortalBasePath = '/doi-search',
) {
    const url = viewport ? buildPortalMapUrl(filters, viewport, includeExtent, basePath) : null;

    return useQuery({
        queryKey: queryKeys.portal.map(url ?? 'waiting-for-viewport'),
        queryFn: ({ signal }) => apiRequest<PortalMapResponse>(url!, { signal }),
        enabled: url !== null,
        placeholderData: keepPreviousData,
        staleTime: 30_000,
        gcTime: 5 * 60_000,
        retry: (failureCount, error) => {
            if (error instanceof ApiError && [400, 422, 429].includes(error.status)) {
                return false;
            }

            return failureCount < 2;
        },
    });
}

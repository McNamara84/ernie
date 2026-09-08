import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo } from 'react';

import { ApiError, apiRequest } from '@/lib/api-client';
import { buildPortalResourcePreviewUrl } from '@/lib/portal-filter-url';
import { queryKeys } from '@/lib/query-keys';
import type { PortalBasePath, PortalResourcePreview } from '@/types/portal';

export function usePortalResourcePreview(resourceId: number, basePath: PortalBasePath, enabled: boolean) {
    const queryClient = useQueryClient();
    const queryKey = useMemo(() => queryKeys.portal.resourcePreview(basePath, resourceId), [basePath, resourceId]);
    const url = buildPortalResourcePreviewUrl(resourceId, basePath);

    const query = useQuery({
        queryKey,
        queryFn: ({ signal }) => apiRequest<PortalResourcePreview>(url, { signal }),
        enabled,
        staleTime: Infinity,
        gcTime: 30 * 60_000,
        retry: (failureCount, error) => {
            if (error instanceof ApiError && [400, 404, 422, 429].includes(error.status)) {
                return false;
            }

            return failureCount < 2;
        },
    });

    useEffect(() => {
        if (!enabled) {
            void queryClient.cancelQueries({ queryKey, exact: true });
        }
    }, [enabled, queryClient, queryKey]);

    return query;
}

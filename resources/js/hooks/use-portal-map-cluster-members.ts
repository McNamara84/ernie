import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '@/lib/api-client';
import { buildPortalMapClusterMembersUrl } from '@/lib/portal-filter-url';
import { queryKeys } from '@/lib/query-keys';
import type { PortalBasePath, PortalFilters, PortalMapClusterMembersResponse, PortalMapViewport } from '@/types/portal';

export function usePortalMapClusterMembers(
    filters: PortalFilters,
    viewport: PortalMapViewport | null,
    clusterId: string | null,
    page: number,
    basePath: PortalBasePath,
    maxZoom: number,
) {
    const url = viewport && clusterId ? buildPortalMapClusterMembersUrl(filters, viewport, clusterId, page, basePath, maxZoom) : null;

    return useQuery({
        queryKey: queryKeys.portal.mapClusterMembers(url ?? 'closed'),
        queryFn: ({ signal }) => apiRequest<PortalMapClusterMembersResponse>(url!, { signal }),
        enabled: url !== null,
        staleTime: 30_000,
        gcTime: 5 * 60_000,
        retry: 1,
    });
}

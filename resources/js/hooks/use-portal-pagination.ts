import { useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { useEffect, useMemo } from 'react';

import { buildPortalCountUrl } from '@/lib/portal-filter-url';
import { queryKeys } from '@/lib/query-keys';
import type { PortalBasePath, PortalPagination } from '@/types/portal';

type PortalCount = Pick<PortalPagination, 'filter_fingerprint' | 'total' | 'last_page'>;

export function usePortalPagination(basePath: PortalBasePath, pagination: PortalPagination) {
    const { filter_fingerprint: fingerprint, per_page: perPage, count_status: status, total, last_page: lastPage } = pagination;
    const queryKey = useMemo(() => queryKeys.portal.count(basePath, fingerprint, perPage), [basePath, fingerprint, perPage]);
    const client = useQueryClient();
    const count = useQuery({
        queryKey,
        queryFn: async ({ signal }) => {
            const url = buildPortalCountUrl(window.location.search, basePath);
            const { data } = await axios.get<PortalCount>(url, { signal });
            return data;
        },
        enabled: Boolean(fingerprint) && status !== 'ready',
        staleTime: Infinity,
        gcTime: 0,
        retry: false,
    });

    // A server-provided count also belongs to subsequent pages of this search.
    useEffect(() => {
        if (status === 'ready' && fingerprint) {
            void client.cancelQueries({ queryKey, exact: true });
            client.setQueryData<PortalCount>(queryKey, { filter_fingerprint: fingerprint, total, last_page: lastPage });
        }
    }, [client, queryKey, fingerprint, status, total, lastPage]);

    let resolved = pagination;
    if (status !== 'ready') {
        if (count.data && count.data.filter_fingerprint === fingerprint) {
            resolved = { ...pagination, total: count.data.total, last_page: count.data.last_page, count_status: 'ready' };
        } else if (count.isError) {
            resolved = { ...pagination, count_status: 'failed' };
        }
    }

    return { pagination: resolved, retryCount: count.refetch };
}

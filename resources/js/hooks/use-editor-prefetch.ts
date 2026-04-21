import { QueryClientContext } from '@tanstack/react-query';
import { useCallback, useContext } from 'react';

import { fetchRorAffiliations } from '@/hooks/use-ror-affiliations';
import { queryKeys } from '@/lib/query-keys';

/**
 * Prefetch vocabularies required by the Data Editor page.
 *
 * Called from navigation components (e.g. sidebar) on hover / focus so that
 * the data is already warm in the TanStack Query cache when the user actually
 * navigates to the editor.
 *
 * The returned function is stable across renders. If the hook is used outside
 * of a `QueryClientProvider` (e.g. in isolated component tests that don't
 * care about prefetching), the returned callback is a safe no-op instead of
 * throwing — prefetching is a best-effort optimisation, not core behaviour.
 */
export function useEditorPrefetch(): () => void {
    const queryClient = useContext(QueryClientContext);

    return useCallback(() => {
        if (!queryClient) {
            return;
        }

        // Fire-and-forget — errors are surfaced via the cache's error state
        // when the editor actually mounts and consumes the data.
        void queryClient.prefetchQuery({
            queryKey: queryKeys.ror.all(),
            queryFn: ({ signal }) => fetchRorAffiliations(signal),
            staleTime: 30 * 60_000,
        });
    }, [queryClient]);
}

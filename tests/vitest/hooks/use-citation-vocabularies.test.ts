import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useCitationVocabularies } from '@/hooks/use-citation-vocabularies';
import { http, HttpResponse, server } from '../helpers/msw-server';

describe('useCitationVocabularies', () => {
    it('fetches vocabularies and exposes them to the consumer', async () => {
        server.use(
            http.get('/related-items/vocabularies', () =>
                HttpResponse.json({
                    resourceTypes: [{ value: 'JournalArticle', label: 'Journal Article' }],
                    relationTypes: [{ id: 1, label: 'Cites' }],
                    contributorTypes: [{ value: 'Editor', label: 'Editor' }],
                }),
            ),
        );

        const { result } = renderHook(() => useCitationVocabularies());

        await waitFor(() => expect(result.current.isLoading).toBe(false));
        expect(result.current.vocabularies.resourceTypes).toHaveLength(1);
        expect(result.current.vocabularies.relationTypes[0]).toEqual({ id: 1, label: 'Cites' });
        expect(result.current.vocabularies.contributorTypes[0].value).toBe('Editor');
        expect(result.current.error).toBeNull();
    });

    it('serves the cached result on subsequent mounts', async () => {
        // The previous test has already populated the module-level cache.
        // Mount another hook with a 500 handler – if the cache works it must never be hit.
        server.use(
            http.get('/related-items/vocabularies', () =>
                HttpResponse.json({ message: 'should not be called' }, { status: 500 }),
            ),
        );

        const { result } = renderHook(() => useCitationVocabularies());
        await act(async () => Promise.resolve());

        expect(result.current.isLoading).toBe(false);
        expect(result.current.vocabularies.resourceTypes).not.toHaveLength(0);
        expect(result.current.error).toBeNull();
    });
});

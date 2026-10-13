import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useRorInputDrafts } from '@/hooks/use-ror-input-drafts';

describe('ROR drafts for sortable editor entries', () => {
    it('keeps drafts attached to their entry IDs across reordering and removes deleted entries', () => {
        const { result } = renderHook(() => useRorInputDrafts());
        const first = { text: '04z8jg394', section: 'authors' as const };
        const second = { text: 'Unknown (012345678)', section: 'authors' as const };
        act(() => {
            result.current.set('author-a-affiliations', first);
            result.current.set('author-b-affiliations', second);
            // Save handlers must see pending text before React renders the next frame.
            expect(result.current.current.current['author-b-affiliations']).toEqual(second);
        });
        act(() => result.current.retain(new Set(['author-b-affiliations', 'author-a-affiliations'])));
        expect(result.current.drafts).toEqual({ 'author-a-affiliations': first, 'author-b-affiliations': second });
        act(() => result.current.retain(new Set(['author-b-affiliations'])));
        expect(result.current.drafts).toEqual({ 'author-b-affiliations': second });
        act(() => result.current.set('author-b-affiliations', null));
        expect(result.current.drafts).toEqual({});
        expect(result.current.current.current).toEqual({});
    });
    it('clears all stale guards when the editor resets its entries', () => {
        const { result } = renderHook(() => useRorInputDrafts());
        act(() => result.current.set('funding-a', { text: '012345678', section: 'fundingReferences' }));
        act(() => result.current.retain(new Set()));
        expect(result.current.drafts).toEqual({});
        expect(result.current.current.current).toEqual({});
    });
});

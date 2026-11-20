import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { createRelatedWorkEditorEntry } from '@/components/curation/fields/related-work/types';
import { snapshotEditorContent } from '@/components/curation/utils/editor-content-snapshot';
import { useRelatedWorkEditor } from '@/hooks/use-related-work-editor';
import type { RelatedIdentifier } from '@/types';

function work(identifier: string, citation_label: string | null = identifier, extra: Partial<RelatedIdentifier> = {}): RelatedIdentifier {
    return { identifier, identifier_type: 'DOI', relation_type: 'Cites', citation_label, ...extra };
}

describe('useRelatedWorkEditor', () => {
    it('sorts only the saved display and exposes unchanged canonical data without editor keys', () => {
        const items = [work('z', 'Zulu', { id: 30 }), work('a', 'Alpha', { id: 10 }), work('empty', null)];
        const originalSnapshot = snapshotEditorContent({ relatedWorks: items });
        const { result } = renderHook(() => useRelatedWorkEditor(items, true));
        expect(result.current.displayEntries.map(({ data }) => data.identifier)).toEqual(['empty', 'a', 'z']);
        expect(result.current.relatedWorks).toEqual(items);
        expect(snapshotEditorContent({ relatedWorks: result.current.relatedWorks })).toBe(originalSnapshot);
        expect(result.current.relatedWorks.every((data) => !('clientId' in data))).toBe(true);
        expect(new Set(result.current.entries.map(({ clientId }) => clientId)).size).toBe(3);
    });

    it('retains insertion order for an unsaved form, including when the first save changes its ID', () => {
        const items = [work('z'), work('a')];
        const { result, rerender } = renderHook(({ saved }) => useRelatedWorkEditor(items, saved), { initialProps: { saved: false } });
        const keys = result.current.displayEntries.map(({ clientId }) => clientId);
        rerender({ saved: true });
        expect(result.current.displayEntries.map(({ data }) => data.identifier)).toEqual(['z', 'a']);
        expect(result.current.displayEntries.map(({ clientId }) => clientId)).toEqual(keys);
    });

    it('keeps edited rows at their session positions and preserves storage IDs and provenance', () => {
        const items = [work('z', 'Zulu', { id: 30 }), work('a', 'Alpha', { id: 10, source: 'relation_suggestion_assistant' })];
        const { result } = renderHook(() => useRelatedWorkEditor(items, true));
        const editedKey = result.current.displayEntries[0].clientId;
        act(() =>
            result.current.setEntries((current) =>
                current.map((entry) => (entry.clientId === editedKey ? { ...entry, data: { ...entry.data, citation_label: 'ZZZ' } } : entry)),
            ),
        );
        expect(result.current.displayEntries.map(({ data }) => data.identifier)).toEqual(['a', 'z']);
        expect(result.current.relatedWorks).toEqual([items[0], { ...items[1], citation_label: 'ZZZ' }]);
        expect(result.current.displayEntries[0].clientId).toBe(editedKey);
    });

    it('retains surviving display keys while deleting and appending in canonical insertion order', () => {
        const { result } = renderHook(() => useRelatedWorkEditor([work('z'), work('a'), work('b')], true));
        const removedKey = result.current.displayEntries[0].clientId;
        const survivorKeys = result.current.displayEntries.slice(1).map(({ clientId }) => clientId);
        const incoming = [createRelatedWorkEditorEntry(work('new-z')), createRelatedWorkEditorEntry(work('new-a', null))];
        act(() => result.current.setEntries((current) => [...current.filter(({ clientId }) => clientId !== removedKey), ...incoming]));
        expect(result.current.relatedWorks.map(({ identifier }) => identifier)).toEqual(['z', 'b', 'new-z', 'new-a']);
        expect(result.current.displayEntries.map(({ data }) => data.identifier)).toEqual(['b', 'z', 'new-z', 'new-a']);
        expect(result.current.displayEntries.slice(0, 2).map(({ clientId }) => clientId)).toEqual(survivorKeys);
        act(() => result.current.setEntries([]));
        expect(result.current.displayEntries).toEqual([]);
        act(() => result.current.setEntries(incoming));
        expect(result.current.displayEntries).toEqual(incoming);
    });

    it('keeps no-op updates stable', () => {
        const { result } = renderHook(() => useRelatedWorkEditor([work('one')]));
        const before = result.current;
        act(() => result.current.setEntries((current) => current));
        expect(result.current).toBe(before);
        act(() => result.current.mergeImported([]));
        expect(result.current).toBe(before);
    });

    it('merges preview imports without re-sorting, overwriting manual data, or replacing existing row identities', () => {
        const initial = [
            work('z', 'Zulu', { id: 12, source: 'relation_suggestion_assistant' }),
            work('shared', null, { id: 13 }),
            work('shared', 'Beta', { id: 14, relation_type: 'References' }),
            work('', null),
        ];
        const { result } = renderHook(() => useRelatedWorkEditor(initial, true));
        const keys = result.current.entries.slice(0, 3).map(({ clientId }) => clientId);
        act(() =>
            result.current.mergeImported([
                work('z', 'Imported replacement', { id: 999 }),
                work('shared', 'Filled label'),
                work('shared', 'Another replacement', { relation_type: 'References' }),
                work('new-z', 'Zulu'),
                work('new-a', 'Alpha'),
                work('', 'Ignored placeholder'),
            ]),
        );
        expect(result.current.entries.slice(0, 3).map(({ clientId }) => clientId)).toEqual(keys);
        expect(result.current.relatedWorks).toEqual([
            initial[0],
            { ...initial[1], citation_label: 'Filled label' },
            initial[2],
            work('new-z', 'Zulu'),
            work('new-a', 'Alpha'),
        ]);
        expect(result.current.displayEntries.map(({ data }) => data.citation_label)).toEqual(['Filled label', 'Beta', 'Zulu', 'Zulu', 'Alpha']);
    });

    it('assigns separate identities to the same identifier with different relation types', () => {
        const { result } = renderHook(() => useRelatedWorkEditor([work('shared'), work('shared', 'shared', { relation_type: 'References' })], true));
        expect(result.current.entries[0].clientId).not.toBe(result.current.entries[1].clientId);
        expect(result.current.displayEntries).toEqual(result.current.entries);
    });
});

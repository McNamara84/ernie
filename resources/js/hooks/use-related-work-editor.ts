import { type SetStateAction, useCallback, useMemo, useState } from 'react';

import { createRelatedWorkEditorEntry, type RelatedWorkEditorEntry } from '@/components/curation/fields/related-work/types';
import { identityPart, mergeImportedEntries } from '@/lib/imported-metadata';
import { getRelatedWorkDisplayOrder } from '@/lib/related-work-ordering';
import type { RelatedIdentifier } from '@/types';

/** Own the display order above the accordion, for the lifetime of the editor. */
export function useRelatedWorkEditor(initialItems: RelatedIdentifier[], sortInitially = false) {
    const [state, setState] = useState(() => {
        const entries = initialItems.map(createRelatedWorkEditorEntry);
        const indices = sortInitially ? getRelatedWorkDisplayOrder(initialItems) : initialItems.map((_, index) => index);
        return { entries, displayOrder: indices.map((index) => entries[index].clientId) };
    });

    const setEntries = useCallback((update: SetStateAction<RelatedWorkEditorEntry[]>) => {
        setState((current) => {
            const entries = typeof update === 'function' ? update(current.entries) : update;
            if (entries === current.entries) return current;

            const available = new Set(entries.map(({ clientId }) => clientId));
            const known = new Set(current.displayOrder);
            return {
                entries,
                displayOrder: [
                    ...current.displayOrder.filter((clientId) => available.has(clientId)),
                    ...entries.filter(({ clientId }) => !known.has(clientId)).map(({ clientId }) => clientId),
                ],
            };
        });
    }, []);

    const mergeImported = useCallback(
        (items: RelatedIdentifier[]) => {
            const incoming = items.map(createRelatedWorkEditorEntry);
            setEntries((current) =>
                mergeImportedEntries(
                    current,
                    incoming,
                    ({ data }) =>
                        JSON.stringify([identityPart(data.identifier), identityPart(data.identifier_type), identityPart(data.relation_type)]),
                    ({ data }) => data.identifier.trim() === '',
                ),
            );
        },
        [setEntries],
    );

    const relatedWorks = useMemo(() => state.entries.map(({ data }) => data), [state.entries]);
    const displayEntries = useMemo(() => {
        const byId = new Map(state.entries.map((entry) => [entry.clientId, entry]));
        return state.displayOrder.map((clientId) => byId.get(clientId)!);
    }, [state]);

    return { entries: state.entries, displayEntries, relatedWorks, setEntries, mergeImported };
}

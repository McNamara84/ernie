import { type SetStateAction, useCallback, useMemo, useState } from 'react';

import { createRelatedWorkEditorEntry, type RelatedWorkEditorEntry } from '@/components/curation/fields/related-work/types';
import { identityPart, mergeImportedEntries } from '@/lib/imported-metadata';
import { getRelatedWorkDisplayOrder } from '@/lib/related-work-ordering';
import type { RelatedIdentifier } from '@/types';

export type SavedWorkLabel = Required<Pick<RelatedIdentifier, 'id' | 'citation_label' | 'citation_label_hash'>>;

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

    const acknowledgeSavedLabels = useCallback(
        (saved?: SavedWorkLabel[]) => {
            if (!saved) return;
            const submitted = state.entries.filter(({ data }) => data.identifier.trim() !== '');
            if (submitted.length !== saved.length) return;
            const byClientId = new Map(submitted.map((entry, index) => [entry.clientId, { before: entry.data, after: saved[index] }]));
            setEntries((current) => {
                let changed = false;
                const updated = current.map((entry) => {
                    const snapshot = byClientId.get(entry.clientId);
                    if (!snapshot) return entry;
                    const { before, after } = snapshot;
                    const data = entry.data;
                    const edited =
                        data.citation_label !== before.citation_label ||
                        data.identifier !== before.identifier ||
                        data.identifier_type !== before.identifier_type;
                    const label = edited ? data.citation_label : after.citation_label;
                    const hash =
                        edited && (before.citation_label ?? '').trim() !== (after.citation_label ?? '')
                            ? data.citation_label_hash
                            : after.citation_label_hash;
                    if (data.id === after.id && data.citation_label === label && data.citation_label_hash === hash) return entry;
                    changed = true;
                    return { ...entry, data: { ...data, id: after.id, citation_label: label, citation_label_hash: hash } };
                });
                return changed ? updated : current;
            });
        },
        [state.entries, setEntries],
    );

    const relatedWorks = useMemo(() => state.entries.map(({ data }) => data), [state.entries]);
    const displayEntries = useMemo(() => {
        const byId = new Map(state.entries.map((entry) => [entry.clientId, entry]));
        return state.displayOrder.map((clientId) => byId.get(clientId)!);
    }, [state]);

    return { entries: state.entries, displayEntries, relatedWorks, setEntries, mergeImported, acknowledgeSavedLabels };
}

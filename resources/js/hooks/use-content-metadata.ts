import { useCallback, useMemo, useRef, useState } from 'react';

import {
    type ContentMetadata,
    contentMetadataErrors,
    contentMetadataSnapshot,
    type FormatEntry,
    formatPayload,
    type ResourceFormat,
    type ResourceSize,
    type SizeEntry,
    sizePayload,
    withClientIds,
} from '@/lib/content-metadata';

const EMPTY: ContentMetadata = { formats: [], sizes: [], fingerprint: null };

function reconcile<T extends ResourceFormat | ResourceSize>(
    current: (T & { clientId: string })[],
    submitted: T[],
    saved: T[],
    key: (row: T) => string,
    clientIds: string[] = [],
) {
    const matched = new Set<number>();
    const ids = new Map<string, number>();
    for (const [index, row] of submitted.entries()) {
        const stored =
            saved.find((candidate) => candidate.id === row.id && row.id !== undefined) ??
            saved.find((candidate) => !matched.has(candidate.id!) && key(candidate) === key(row));
        if (stored?.id !== undefined) {
            matched.add(stored.id);
            ids.set(row.id !== undefined ? `id:${row.id}` : key(row), stored.id);
            if (clientIds[index]) ids.set(`client:${clientIds[index]}`, stored.id);
        }
    }
    const result = current
        .filter((row) => {
            const sourceIndex = clientIds.indexOf(row.clientId);
            const source = sourceIndex >= 0 ? submitted[sourceIndex] : submitted.find((entry) => entry.id === row.id && row.id !== undefined);
            return !source || source.id === undefined || saved.some((entry) => entry.id === source.id) || key(row) !== key(source);
        })
        .map((row) => {
            const id = ids.get(`client:${row.clientId}`) ?? ids.get(row.id !== undefined ? `id:${row.id}` : key(row));
            if (id === undefined) return row;
            const index = clientIds.indexOf(row.clientId);
            const source = index >= 0 ? submitted[index] : submitted.find((entry) => entry.id === row.id && row.id !== undefined);
            const stored = saved.find((entry) => entry.id === id);
            if (source && stored && key(source) === key(row)) return { ...stored, clientId: row.clientId };
            return { ...row, importKey: undefined, id };
        });
    // Rows added by assistance while unchanged metadata was being saved are retained.
    for (const row of saved) {
        if (!matched.has(row.id!)) result.push({ ...row, clientId: crypto.randomUUID() });
    }
    return result;
}

export function useContentMetadata(initial: ContentMetadata = EMPTY) {
    const [formats, setFormats] = useState<FormatEntry[]>(() => withClientIds(initial.formats));
    const [sizes, setSizes] = useState<SizeEntry[]>(() => withClientIds(initial.sizes));
    const [fingerprint, setFingerprint] = useState(initial.fingerprint);
    const [baseline, setBaseline] = useState(() =>
        contentMetadataSnapshot(initial.fingerprint ? initial.formats : [], initial.fingerprint ? initial.sizes : []),
    );
    const lastSaved = useRef<ContentMetadata | null>(null);
    const snapshot = useMemo(() => contentMetadataSnapshot(formats, sizes), [formats, sizes]);
    const payload = useMemo(
        () => ({
            formats: formatPayload(formats),
            sizes: sizePayload(sizes),
            contentMetadataFingerprint: fingerprint,
            contentMetadataChanged: snapshot !== baseline,
            contentMetadataClientIds: {
                formats: formats.filter((row) => row.id !== undefined || row.value.trim() !== '').map((row) => row.clientId),
                sizes: sizes
                    .filter((row) => row.id !== undefined || (row.numeric_value ?? '').trim() !== '' || (row.unit ?? '') !== '')
                    .map((row) => row.clientId),
            },
        }),
        [formats, sizes, fingerprint, snapshot, baseline],
    );
    const errors = useMemo(() => contentMetadataErrors(sizes), [sizes]);

    const applySaved = useCallback(
        (
            saved: ContentMetadata | undefined,
            submitted: { formats: ResourceFormat[]; sizes: ResourceSize[]; contentMetadataClientIds?: { formats: string[]; sizes: string[] } },
        ) => {
            if (!saved) return;
            lastSaved.current = saved;
            setFormats((current) =>
                reconcile(current, submitted.formats, saved.formats, (row) => row.value, submitted.contentMetadataClientIds?.formats),
            );
            setSizes((current) =>
                reconcile(
                    current,
                    submitted.sizes,
                    saved.sizes,
                    (row) => contentMetadataSnapshot([], [row]),
                    submitted.contentMetadataClientIds?.sizes,
                ),
            );
            setFingerprint(saved.fingerprint);
            setBaseline(contentMetadataSnapshot(saved.formats, saved.sizes));
        },
        [],
    );

    return { formats, setFormats, sizes, setSizes, payload, snapshot, errors, applySaved, lastSaved };
}

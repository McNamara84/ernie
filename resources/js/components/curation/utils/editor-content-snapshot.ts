import type { SavedWorkLabel } from '@/hooks/use-related-work-editor';
import type { RelatedIdentifier } from '@/types';

const CLIENT_UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function normalize(value: unknown, parentKey?: string): unknown {
    if (Array.isArray(value)) return value.map((entry) => normalize(entry, parentKey));
    if (value === null || typeof value !== 'object') return value;

    return Object.fromEntries(
        Object.entries(value)
            .filter(
                ([key, entry]) =>
                    entry !== undefined &&
                    !(parentKey === 'relatedWorks' && ['id', 'citation_proof', 'citation_label_hash'].includes(key)) &&
                    !(parentKey === 'instruments' && key === 'resolution') &&
                    !(key === 'id' && (parentKey === 'spatialTemporalCoverages' || (typeof entry === 'string' && CLIENT_UUID.test(entry)))) &&
                    !(parentKey === 'spatialTemporalCoverages' && (key === 'storageId' || key === 'copyFromId')),
            )
            .sort(([left], [right]) => left.localeCompare(right))
            .map(([key, entry]) => [key, normalize(entry, key)]),
    );
}

/** Compare editable content independently of generated row IDs and object key order. */
export function snapshotEditorContent(value: unknown): string {
    return JSON.stringify(normalize(value));
}

export function draftAutosaveSignature(
    payload: { relatedIdentifiers: { id?: number; citationLabel?: string; citationProof?: string; originalCitationLabelHash?: string }[] },
    savedLabels?: SavedWorkLabel[],
): string {
    const accepted = savedLabels?.length === payload.relatedIdentifiers.length ? savedLabels : undefined;
    const relatedIdentifiers = payload.relatedIdentifiers.map(
        ({ id: _id, citationProof: _proof, originalCitationLabelHash: _hash, citationLabel, ...fields }, index) => ({
            ...fields,
            citationLabel: accepted ? accepted[index].citation_label?.trim() || undefined : citationLabel,
        }),
    );
    return JSON.stringify({ ...payload, relatedIdentifiers });
}

export function snapshotWithSavedCitationLabels(snapshot: string, savedLabels?: SavedWorkLabel[]): string {
    if (!savedLabels?.length) return snapshot;
    const content = JSON.parse(snapshot) as { relatedWorks: RelatedIdentifier[] };
    const submitted = content.relatedWorks.filter((work) => work.identifier.trim() !== '');
    if (submitted.length !== savedLabels.length) return snapshot;
    submitted.forEach((work, index) => {
        work.citation_label = savedLabels[index].citation_label;
    });
    return snapshotEditorContent(content);
}

export function snapshotWithSavedDoi(snapshot: string, doi: string): string {
    const content = JSON.parse(snapshot) as { form: Record<string, unknown> };
    return snapshotEditorContent({ ...content, form: { ...content.form, doi } });
}

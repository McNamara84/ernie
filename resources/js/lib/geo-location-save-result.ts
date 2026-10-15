import type { SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';
import { snapshotEditorContent } from '@/components/curation/utils/editor-content-snapshot';

export interface GeoLocationChanges {
    removedCount: number;
    idMap: Record<string, string>;
    removedIds: string[];
}

/** Keep edits made during a request; only remove duplicates that are still unchanged. */
export function applyGeoLocationChanges<T extends { id: string; storageId?: string; copyFromId?: string }>(
    current: T[],
    submitted: T[],
    changes: GeoLocationChanges,
    preserveEditorKeys = false,
): T[] {
    const originals = new Map(submitted.map((entry) => [entry.id, entry]));
    return current.flatMap((entry) => {
        const original = originals.get(entry.id);
        if (!original) return [entry];
        const inputId = original.storageId ?? original.id;
        if (changes.removedIds.includes(inputId)) {
            if (JSON.stringify(entry) === JSON.stringify(original)) return [];
            return [preserveEditorKeys ? { ...entry, storageId: crypto.randomUUID(), copyFromId: changes.idMap[inputId] } : entry];
        }
        const id = changes.idMap[inputId];
        if (!id || (!entry.copyFromId && id === (preserveEditorKeys ? (entry.storageId ?? entry.id) : entry.id))) return [entry];
        return [preserveEditorKeys ? { ...entry, storageId: id, copyFromId: undefined } : { ...entry, id, copyFromId: undefined }];
    });
}

/** Record the saved baseline without marking concurrent edits as saved. */
export function snapshotWithSavedGeoLocations(snapshot: string, submitted: SpatialTemporalCoverageEntry[], changes?: GeoLocationChanges): string {
    if (!changes) return snapshot;
    const content = JSON.parse(snapshot) as Record<string, unknown>;
    return snapshotEditorContent({ ...content, spatialTemporalCoverages: applyGeoLocationChanges(submitted, submitted, changes) });
}

import { expect, it } from 'vitest';

import type { SpatialTemporalCoverageEntry } from '@/components/curation/fields/spatial-temporal-coverage/types';
import { snapshotEditorContent } from '@/components/curation/utils/editor-content-snapshot';
import { applyGeoLocationChanges, snapshotWithSavedGeoLocations } from '@/lib/geo-location-save-result';

const entry: SpatialTemporalCoverageEntry = {
    id: 'first',
    type: 'point',
    latMin: '52',
    lonMin: '13',
    latMax: '',
    lonMax: '',
    startDate: '',
    endDate: '',
    startTime: '',
    endTime: '',
    timezone: '',
    description: 'GFZ',
};
const changes = { removedCount: 1, idMap: { first: '42', copy: '42' }, removedIds: ['copy'] };

it('removes only the incoming duplicate and adopts the persisted ID', () => {
    const submitted = [entry, { ...entry, id: 'copy' }];
    expect(applyGeoLocationChanges(submitted, submitted, changes)).toEqual([{ ...entry, id: '42' }]);
});
it('keeps edits and new entries made while the save was in flight', () => {
    const submitted = [entry, { ...entry, id: 'copy' }];
    const current = [
        { ...entry, description: 'Edited' },
        { ...entry, id: 'copy', lonMin: '14' },
        { ...entry, id: 'new' },
    ];
    expect(applyGeoLocationChanges(current, submitted, changes)).toEqual([{ ...current[0], id: '42' }, current[1], current[2]]);
});
it('does not resurrect entries removed during a save or remove historical duplicates', () => {
    expect(applyGeoLocationChanges([], [entry], changes)).toEqual([]);
    const historical = [
        { ...entry, id: '42' },
        { ...entry, id: '43' },
    ];
    expect(applyGeoLocationChanges(historical, historical, { removedCount: 0, idMap: { '42': '42', '43': '43' }, removedIds: [] })).toEqual(
        historical,
    );
});

it('records the merged baseline independently of technical row IDs and subsequent edits', () => {
    const submitted = [entry, { ...entry, id: 'copy' }];
    const snapshot = snapshotEditorContent({ form: { title: 'Submitted title' }, spatialTemporalCoverages: submitted });
    const saved = snapshotWithSavedGeoLocations(snapshot, submitted, changes);
    expect(saved).toBe(snapshotEditorContent({ form: { title: 'Submitted title' }, spatialTemporalCoverages: [{ ...entry, id: '42' }] }));
    expect(saved).not.toBe(snapshotEditorContent({ form: { title: 'Edited during save' }, spatialTemporalCoverages: [{ ...entry, id: '42' }] }));
    expect(snapshotWithSavedGeoLocations(snapshot, submitted)).toBe(snapshot);
});

it('keeps stable editor keys when adopting server IDs and handles subsequent saves', () => {
    const submitted = [entry, { ...entry, id: 'copy' }];
    const saved = applyGeoLocationChanges(submitted, submitted, changes, true);
    expect(saved).toEqual([{ ...entry, storageId: '42', copyFromId: undefined }]);
    expect(applyGeoLocationChanges(saved, saved, { removedCount: 0, idMap: { '42': '42' }, removedIds: [] }, true)).toEqual(saved);
});

it('keeps a changed merged row editable as a new row while retaining its metadata source', () => {
    const submitted = [
        { ...entry, id: '42' },
        { ...entry, id: '43' },
    ];
    const current = [{ ...submitted[0], lonMin: '14' }, submitted[1]];
    const saved = applyGeoLocationChanges(current, submitted, { removedCount: 1, idMap: { '42': '43', '43': '43' }, removedIds: ['42'] }, true);
    expect(saved[0]).toEqual(expect.objectContaining({ id: '42', lonMin: '14', storageId: expect.any(String), copyFromId: '43' }));
    expect(saved[0].storageId).not.toBe('42');
    expect(saved[1]).toEqual(submitted[1]);
});

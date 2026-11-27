import { describe, expect, it } from 'vitest';

import {
    draftAutosaveSignature,
    snapshotEditorContent,
    snapshotWithSavedCitationLabels,
    snapshotWithSavedDoi,
} from '@/components/curation/utils/editor-content-snapshot';

describe('editor content snapshot', () => {
    it('ignores instrument diagnostics but tracks newly resolved names and original PIDs', () => {
        const instrument = { pid: '21.test/a', pidType: 'Handle', name: 'Name' };
        const before = snapshotEditorContent({ instruments: [instrument] });
        expect(snapshotEditorContent({ instruments: [{ ...instrument, resolution: { status: 'identifier_conflict' } }] })).toBe(before);
        expect(snapshotEditorContent({ instruments: [{ ...instrument, name: 'Resolved name' }] })).not.toBe(before);
        expect(snapshotEditorContent({ instruments: [{ ...instrument, pid: '21.test/b' }] })).not.toBe(before);
    });
    it('ignores generated row IDs and object key order while preserving real content', () => {
        const first = {
            titles: [{ id: '5a1b0f42-3e6e-4b32-b741-97b442d50200', title: 'A title', titleType: 'main-title' }],
            form: { year: '2026', doi: '' },
        };
        const sameContent = {
            form: { doi: '', year: '2026' },
            titles: [{ titleType: 'main-title', title: 'A title', id: '5a1b0f42-3e6e-4b32-b741-97b442d50201' }],
        };

        expect(snapshotEditorContent(first)).toBe(snapshotEditorContent(sameContent));
        expect(snapshotEditorContent({ ...first, titles: [{ ...first.titles[0], title: 'Another title' }] })).not.toBe(snapshotEditorContent(first));
    });

    it('matches saved labels to submitted identifiers while retaining empty editor rows', () => {
        const relatedWorks = [{ identifier: 'a' }, { identifier: '  ', citation_label: 'Placeholder' }, { identifier: 'b', citation_label: 'Old' }];
        const snapshot = snapshotEditorContent({ relatedWorks });
        const labels = [
            { id: 1, citation_label: 'Accepted', citation_label_hash: 'first' },
            { id: 2, citation_label: null, citation_label_hash: 'second' },
        ];
        expect(JSON.parse(snapshotWithSavedCitationLabels(snapshot, labels)).relatedWorks).toEqual([
            { identifier: 'a', citation_label: 'Accepted' },
            relatedWorks[1],
            { identifier: 'b', citation_label: null },
        ]);
        expect(snapshotWithSavedCitationLabels(snapshot, labels.slice(1))).toBe(snapshot);

        const payload = {
            relatedIdentifiers: [
                { identifier: 'a', source: 'assistant', citationProof: 'proof' },
                { identifier: 'b', citationLabel: 'Old' },
            ],
        };
        const accepted = {
            relatedIdentifiers: [
                { id: 1, identifier: 'a', citationLabel: 'Accepted', source: 'assistant' },
                { id: 2, identifier: 'b' },
            ],
        };
        expect(draftAutosaveSignature(payload, labels)).toBe(draftAutosaveSignature(accepted));
        expect(draftAutosaveSignature(payload, labels.slice(1))).toBe(draftAutosaveSignature(payload));
    });

    it('keeps server IDs and updates only the saved DOI after registration', () => {
        const snapshot = snapshotEditorContent({ form: { doi: '', year: '2026' }, relatedItems: [{ id: 42, title: 'Citation' }] });

        expect(snapshotWithSavedDoi(snapshot, '10.5880/new-doi')).toBe(
            snapshotEditorContent({ form: { doi: '10.5880/new-doi', year: '2026' }, relatedItems: [{ id: 42, title: 'Citation' }] }),
        );
        expect(snapshotWithSavedDoi(snapshot, '10.5880/new-doi')).not.toBe(
            snapshotEditorContent({ form: { doi: '10.5880/new-doi', year: '2026' }, relatedItems: [{ id: 43, title: 'Citation' }] }),
        );
    });
});

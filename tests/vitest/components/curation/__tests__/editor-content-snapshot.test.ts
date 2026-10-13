import { describe, expect, it } from 'vitest';

import { snapshotEditorContent, snapshotWithSavedDoi } from '@/components/curation/utils/editor-content-snapshot';

describe('editor content snapshot', () => {
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
        expect(snapshotEditorContent({ ...first, titles: [{ ...first.titles[0], title: 'Another title' }] })).not.toBe(
            snapshotEditorContent(first),
        );
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

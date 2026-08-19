import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useEditorLoadTimeline } from '@/hooks/use-editor-load-timeline';

describe('useEditorLoadTimeline', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows every message in order for two seconds and keeps the last one', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-19T10:00:00Z'));

        const { result } = renderHook(() => useEditorLoadTimeline('11111111-1111-4111-8111-111111111111'));

        expect(result.current.message).toBe('Preparing the Data Editor for the Data Curators work');

        const expectedMessages = [
            'Load user-specific settings for Data Editor',
            'Ask ELMO if Cookie Monster still has any cookies',
            'Load unicorns into the DataCite cache',
            'Groan under the weight of the huge dataset',
            'Who on earth works with such massive datasets?',
        ];

        expectedMessages.forEach((expectedMessage) => {
            act(() => vi.advanceTimersByTime(2_000));
            expect(result.current.message).toBe(expectedMessage);
        });

        act(() => vi.advanceTimersByTime(20_000));
        expect(result.current.message).toBe('Who on earth works with such massive datasets?');
        expect(result.current.messageIndex).toBe(5);
    });

    it('continues the same timeline when the page component changes', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-19T10:00:00Z'));
        const token = '22222222-2222-4222-8222-222222222222';

        const firstPage = renderHook(() => useEditorLoadTimeline(token));
        act(() => vi.advanceTimersByTime(5_000));
        expect(firstPage.result.current.messageIndex).toBe(2);
        firstPage.unmount();

        const editorPage = renderHook(() => useEditorLoadTimeline(token));
        expect(editorPage.result.current.messageIndex).toBe(2);
    });
});

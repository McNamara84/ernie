import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useEditorSlowLoadReporter } from '@/hooks/use-editor-slow-load-reporter';
import type { EditorClientLoadStage } from '@/types/editor-load';

const { axiosPostMock } = vi.hoisted(() => ({ axiosPostMock: vi.fn() }));

vi.mock('axios', () => ({
    default: {
        post: axiosPostMock,
    },
}));

const context = {
    token: '55555555-5555-4555-8555-555555555555',
    resourceId: 42,
    serverProgress: 75,
    slowThresholdMs: 12_000,
};

interface ReporterProps {
    stage: EditorClientLoadStage;
    progress: number;
}

describe('useEditorSlowLoadReporter', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('reports the latest real phase once when twelve seconds are reached', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-19T10:00:00Z'));
        axiosPostMock.mockResolvedValue({});

        const { rerender, unmount } = renderHook(({ stage, progress }: ReporterProps) => useEditorSlowLoadReporter(context, true, stage, progress), {
            initialProps: { stage: 'loader' as EditorClientLoadStage, progress: 25 },
        });

        act(() => vi.advanceTimersByTime(11_999));
        expect(axiosPostMock).not.toHaveBeenCalled();

        rerender({ stage: 'client_vocabularies', progress: 90 });
        await act(async () => vi.advanceTimersByTimeAsync(1));

        expect(axiosPostMock).toHaveBeenCalledOnce();
        expect(axiosPostMock).toHaveBeenCalledWith(`/editor/resource-loads/${context.token}/slow`, {
            stage: 'client_vocabularies',
            progress: 90,
        });

        unmount();
        renderHook(() => useEditorSlowLoadReporter(context, true, 'client_ready', 100));
        await act(async () => vi.advanceTimersByTimeAsync(1_000));
        expect(axiosPostMock).toHaveBeenCalledOnce();
    });

    it('does not report after loading has completed', () => {
        vi.useFakeTimers();
        axiosPostMock.mockReset();

        renderHook(() => useEditorSlowLoadReporter(context, false, 'client_ready', 100));
        act(() => vi.advanceTimersByTime(20_000));

        expect(axiosPostMock).not.toHaveBeenCalled();
    });
});

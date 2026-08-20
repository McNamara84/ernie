import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useEditorSlowLoadReporter } from '@/hooks/use-editor-slow-load-reporter';
import { clearEditorLoadTimeline, hasReportedSlowEditorLoad } from '@/lib/editor-load';
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
    active: boolean;
    stage: EditorClientLoadStage;
    progress: number;
}

describe('useEditorSlowLoadReporter', () => {
    beforeEach(() => {
        axiosPostMock.mockReset();
        clearEditorLoadTimeline(context.token);
    });

    afterEach(() => {
        clearEditorLoadTimeline(context.token);
        vi.useRealTimers();
    });

    it('reports the latest real phase once when twelve seconds are reached', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-19T10:00:00Z'));
        axiosPostMock.mockResolvedValue({});

        const { rerender, unmount } = renderHook(
            ({ active, stage, progress }: ReporterProps) => useEditorSlowLoadReporter(context, active, stage, progress),
            {
                initialProps: { active: true, stage: 'loader' as EditorClientLoadStage, progress: 25 },
            },
        );

        act(() => vi.advanceTimersByTime(11_999));
        expect(axiosPostMock).not.toHaveBeenCalled();

        rerender({ active: true, stage: 'client_vocabularies', progress: 90 });
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

        renderHook(() => useEditorSlowLoadReporter(context, false, 'client_ready', 100));
        act(() => vi.advanceTimersByTime(20_000));

        expect(axiosPostMock).not.toHaveBeenCalled();
    });

    it('marks the load as reported only after a retry is acknowledged', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-19T10:00:00Z'));
        axiosPostMock.mockRejectedValueOnce(new Error('temporary outage')).mockResolvedValueOnce({});

        const { unmount } = renderHook(() => useEditorSlowLoadReporter(context, true, 'client_vocabularies', 90));

        await act(async () => vi.advanceTimersByTimeAsync(12_000));
        expect(axiosPostMock).toHaveBeenCalledOnce();
        expect(hasReportedSlowEditorLoad(context.token)).toBe(false);

        await act(async () => vi.advanceTimersByTimeAsync(999));
        expect(axiosPostMock).toHaveBeenCalledOnce();

        await act(async () => vi.advanceTimersByTimeAsync(1));
        expect(axiosPostMock).toHaveBeenCalledTimes(2);
        expect(hasReportedSlowEditorLoad(context.token)).toBe(true);

        unmount();
        renderHook(() => useEditorSlowLoadReporter(context, true, 'client_ready', 100));
        await act(async () => vi.advanceTimersByTimeAsync(1_000));
        expect(axiosPostMock).toHaveBeenCalledTimes(2);
    });

    it('stops retrying after three failed attempts', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-19T10:00:00Z'));
        axiosPostMock.mockRejectedValue(new Error('persistent outage'));

        renderHook(() => useEditorSlowLoadReporter(context, true, 'client_vocabularies', 90));

        await act(async () => vi.advanceTimersByTimeAsync(14_000));
        expect(axiosPostMock).toHaveBeenCalledTimes(3);

        await act(async () => vi.advanceTimersByTimeAsync(10_000));
        expect(axiosPostMock).toHaveBeenCalledTimes(3);
    });

    it('cancels a pending retry when loading is no longer active', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-19T10:00:00Z'));
        axiosPostMock.mockRejectedValue(new Error('temporary outage'));

        const { rerender } = renderHook(({ active }: { active: boolean }) => useEditorSlowLoadReporter(context, active, 'client_vocabularies', 90), {
            initialProps: { active: true },
        });

        await act(async () => vi.advanceTimersByTimeAsync(12_000));
        expect(axiosPostMock).toHaveBeenCalledOnce();

        rerender({ active: false });
        await act(async () => vi.advanceTimersByTimeAsync(2_000));
        expect(axiosPostMock).toHaveBeenCalledOnce();
    });
});

import { act, renderHook, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useInstrumentNameResolution } from '@/hooks/use-instrument-name-resolution';
import { apiRequest } from '@/lib/api-client';
import type { InstrumentSelection } from '@/types';

vi.mock('@/lib/api-client', () => ({ apiRequest: vi.fn() }));
const request = vi.mocked(apiRequest);
const unresolved: InstrumentSelection = { pid: '21.test/a', pidType: 'Handle', name: '21.test/a' };
const resolved: InstrumentSelection = { ...unresolved, name: 'Instrument A', resolution: { status: 'matched' } };

function setup(initial: InstrumentSelection[]) {
    return renderHook(() => {
        const [instruments, setInstruments] = useState(initial);
        return { instruments, setInstruments, ...useInstrumentNameResolution(instruments, setInstruments) };
    });
}

describe('instrument name resolution', () => {
    beforeEach(() => vi.resetAllMocks());

    it('accepts server-resolved initial data without an additional request', () => {
        const { result } = setup([resolved]);
        expect(request).not.toHaveBeenCalled();
        expect(result.current.instruments).toEqual([resolved]);
    });

    it('resolves missing names once and keeps identifiers and ordering', async () => {
        request.mockResolvedValue({ instruments: [resolved] });
        const { result } = setup([unresolved]);
        await waitFor(() => expect(result.current.instruments).toEqual([resolved]));
        expect(request).toHaveBeenCalledTimes(1);
        expect(request).toHaveBeenCalledWith(
            '/editor/instruments/resolve',
            expect.objectContaining({
                method: 'POST',
                body: { instruments: [unresolved] },
            }),
        );
    });

    it('retries an unavailable local registry without changing other form selections', async () => {
        const unavailable: InstrumentSelection = { ...unresolved, resolution: { status: 'unavailable' } };
        request.mockResolvedValue({ instruments: [resolved] });
        const { result } = setup([unavailable]);
        act(() => result.current.resolveAgain());
        await waitFor(() => expect(result.current.instruments).toEqual([resolved]));
    });

    it('does not restore instruments removed while the request is in flight', async () => {
        let finish!: (value: unknown) => void;
        request.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const { result } = setup([unresolved]);
        act(() => result.current.setInstruments([]));
        await act(async () => finish({ instruments: [resolved] }));
        expect(result.current.instruments).toEqual([]);
        expect(result.current.isResolving).toBe(false);
        expect((request.mock.calls[0][1]?.signal as AbortSignal).aborted).toBe(true);
    });

    it('ignores outdated responses after a new import and preserves newer names', async () => {
        let finish!: (value: unknown) => void;
        request.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const replacement: InstrumentSelection = { pid: '21.test/b', pidType: 'Handle', name: 'Curated B' };
        request.mockResolvedValueOnce({ instruments: [{ ...replacement, resolution: { status: 'not_found' } }] });
        const { result } = setup([unresolved]);
        act(() => result.current.setInstruments([replacement]));
        await waitFor(() => expect(result.current.instruments[0].resolution?.status).toBe('not_found'));
        await act(async () => finish({ instruments: [resolved] }));
        expect(result.current.instruments[0].name).toBe('Curated B');
    });

    it('retains names after a failed request and allows an explicit retry', async () => {
        request.mockRejectedValueOnce(new Error('Unavailable')).mockResolvedValueOnce({ instruments: [resolved] });
        const { result } = setup([unresolved]);
        await waitFor(() => expect(result.current.resolutionError).toBe('Unavailable'));
        expect(result.current.instruments).toEqual([unresolved]);
        act(() => result.current.resolveAgain());
        await waitFor(() => expect(result.current.instruments).toEqual([resolved]));
        expect(result.current.resolutionError).toBeNull();
    });

    it.each([{ instruments: [] }, { instruments: [{ ...resolved, pid: 'wrong' }] }, { instruments: [{ ...resolved, resolution: undefined }] }])(
        'rejects an inconsistent response without rewriting selections',
        async (response) => {
            request.mockResolvedValue(response);
            const { result } = setup([unresolved]);
            await waitFor(() => expect(result.current.resolutionError).toBeTruthy());
            expect(result.current.instruments).toEqual([unresolved]);
        },
    );
});

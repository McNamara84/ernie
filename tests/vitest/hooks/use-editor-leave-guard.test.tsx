import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { shouldWarnForEditorVisit, useEditorLeaveGuard } from '@/hooks/use-editor-leave-guard';

type GuardEvent = { detail: { visit: { url: URL; method: string; only?: unknown[]; prefetch?: boolean } } };

const { mockRouterOn, mockRemoveListener } = vi.hoisted(() => ({
    mockRemoveListener: vi.fn(),
    mockRouterOn: vi.fn((_name: string, _listener: (event: GuardEvent) => boolean | void) => mockRemoveListener),
}));

vi.mock('@inertiajs/react', () => ({ router: { on: mockRouterOn } }));

const visitEvent = (url: string): GuardEvent => ({
    detail: { visit: { url: new URL(url, window.location.href), method: 'get' } },
});

describe('editor leave guard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        window.history.replaceState({}, '', '/editor?resourceId=42');
    });

    it('guards document unload only while there are unsaved changes', () => {
        const { rerender, unmount } = renderHook(({ dirty }) => useEditorLeaveGuard(dirty), { initialProps: { dirty: false } });
        const cleanUnload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(cleanUnload);
        expect(cleanUnload.defaultPrevented).toBe(false);
        expect(mockRouterOn).not.toHaveBeenCalled();

        rerender({ dirty: true });
        const dirtyUnload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(dirtyUnload);
        expect(dirtyUnload.defaultPrevented).toBe(true);
        expect(mockRouterOn).toHaveBeenCalledWith('before', expect.any(Function));

        rerender({ dirty: false });
        const savedUnload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(savedUnload);
        expect(savedUnload.defaultPrevented).toBe(false);
        expect(mockRemoveListener).toHaveBeenCalledTimes(1);
        unmount();
    });

    it('cancels an internal visit when the user chooses to stay and allows it otherwise', () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
        renderHook(() => useEditorLeaveGuard(true));
        const beforeVisit = mockRouterOn.mock.calls.at(-1)?.[1];

        expect(beforeVisit?.(visitEvent('/resources'))).toBe(false);
        expect(beforeVisit?.(visitEvent('/resources'))).toBeUndefined();
        expect(confirm).toHaveBeenCalledTimes(2);
        confirm.mockRestore();
    });

    it('permits one explicitly approved post-save visit without asking again', () => {
        const confirm = vi.spyOn(window, 'confirm');
        const { result } = renderHook(() => useEditorLeaveGuard(true));
        const beforeVisit = mockRouterOn.mock.calls.at(-1)?.[1];

        act(() => {
            result.current.allowNextInternalVisit('/resources');
            expect(beforeVisit?.(visitEvent('/resources'))).toBeUndefined();
        });
        expect(confirm).not.toHaveBeenCalled();
        confirm.mockRestore();
    });

    it.each([
        ['/editor?resourceId=42#authors', 'get', false],
        ['/editor?resourceId=99', 'get', true],
        ['/resources', 'get', true],
        ['/resources', 'post', false],
        ['https://example.org/', 'get', false],
    ])('classifies navigation to %s with %s as guard=%s', (url, method, expected) => {
        expect(shouldWarnForEditorVisit('http://localhost/editor?resourceId=42', { url, method })).toBe(expected);
    });

    it('lets a same-page partial reload proceed', () => {
        expect(shouldWarnForEditorVisit('http://localhost/editor?resourceId=42', {
            url: '/editor?resourceId=42&fresh=1',
            method: 'get',
            only: ['resourceTypes'],
        })).toBe(false);
        expect(shouldWarnForEditorVisit('http://localhost/editor?resourceId=42', {
            url: '/editor?resourceId=43',
            method: 'get',
            only: ['resourceTypes'],
        })).toBe(true);
    });

    it('ignores link prefetch visits without showing a confirmation', () => {
        const confirm = vi.spyOn(window, 'confirm');
        renderHook(() => useEditorLeaveGuard(true));
        const beforeVisit = mockRouterOn.mock.calls.at(-1)?.[1];
        const event = visitEvent('/resources');
        expect(beforeVisit?.({ detail: { visit: { ...event.detail.visit, prefetch: true } } })).toBeUndefined();
        expect(confirm).not.toHaveBeenCalled();
        confirm.mockRestore();
    });
});

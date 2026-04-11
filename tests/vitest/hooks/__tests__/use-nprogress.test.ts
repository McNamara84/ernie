import '@testing-library/jest-dom/vitest';

import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Mock dependencies — vi.mock is hoisted, so we cannot reference outer variables in the factory.
// Instead, use vi.hoisted() or import the mock after vi.mock.

vi.mock('nprogress', () => ({
    default: {
        start: vi.fn(),
        done: vi.fn(),
        remove: vi.fn(),
        configure: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        on: vi.fn(),
    },
}));

vi.mock('@/hooks/use-reduced-motion', () => ({
    useReducedMotion: vi.fn(() => false),
}));

import NProgress from 'nprogress';
import { router } from '@inertiajs/react';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { useNProgress } from '@/hooks/use-nprogress';

describe('useNProgress', () => {
    let startCallback: () => void;
    let finishCallback: () => void;
    const removeStart = vi.fn();
    const removeFinish = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useReducedMotion).mockReturnValue(false);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        vi.mocked(router.on).mockImplementation(((event: string, cb: () => void) => {
            if (event === 'start') {
                startCallback = cb;
                return removeStart;
            }
            if (event === 'finish') {
                finishCallback = cb;
                return removeFinish;
            }
            return vi.fn();
        }) as any);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('configures NProgress on mount', () => {
        renderHook(() => useNProgress());

        expect(NProgress.configure).toHaveBeenCalledWith(
            expect.objectContaining({
                showSpinner: false,
                minimum: 0.1,
            }),
        );
    });

    it('starts NProgress on Inertia start event', () => {
        renderHook(() => useNProgress());

        startCallback();
        expect(NProgress.start).toHaveBeenCalled();
    });

    it('finishes NProgress on Inertia finish event', () => {
        renderHook(() => useNProgress());

        finishCallback();
        expect(NProgress.done).toHaveBeenCalled();
    });

    it('removes listeners on unmount', () => {
        const { unmount } = renderHook(() => useNProgress());

        unmount();
        expect(removeStart).toHaveBeenCalled();
        expect(removeFinish).toHaveBeenCalled();
        expect(NProgress.remove).toHaveBeenCalled();
    });

    it('sets speed to 0 when reduced motion is preferred', () => {
        vi.mocked(useReducedMotion).mockReturnValue(true);

        renderHook(() => useNProgress());

        expect(NProgress.configure).toHaveBeenCalledWith(
            expect.objectContaining({
                speed: 0,
                trickleSpeed: 0,
            }),
        );
    });

    it('uses normal speed when reduced motion is not preferred', () => {
        vi.mocked(useReducedMotion).mockReturnValue(false);

        renderHook(() => useNProgress());

        expect(NProgress.configure).toHaveBeenCalledWith(
            expect.objectContaining({
                speed: 300,
                trickleSpeed: 200,
            }),
        );
    });
});

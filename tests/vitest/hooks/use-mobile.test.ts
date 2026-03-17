import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useIsMobile } from '@/hooks/use-mobile';

function setupMobileViewport() {
    // The hook uses window.innerWidth < 768, not matchMedia.matches
    Object.defineProperty(window, 'innerWidth', { value: 500, writable: true, configurable: true });
    const listeners: Array<() => void> = [];
    const mql = {
        matches: true,
        media: '(max-width: 767px)',
        addEventListener: vi.fn((_event: string, cb: () => void) => {
            listeners.push(cb);
        }),
        removeEventListener: vi.fn(),
        dispatchChange() {
            listeners.forEach((cb) => cb());
        },
    };
    window.matchMedia = vi.fn().mockReturnValue(mql);
    return mql;
}

function setupDesktopViewport() {
    Object.defineProperty(window, 'innerWidth', { value: 1024, writable: true, configurable: true });
    const listeners: Array<() => void> = [];
    const mql = {
        matches: false,
        media: '(max-width: 767px)',
        addEventListener: vi.fn((_event: string, cb: () => void) => {
            listeners.push(cb);
        }),
        removeEventListener: vi.fn(),
        dispatchChange() {
            listeners.forEach((cb) => cb());
        },
    };
    window.matchMedia = vi.fn().mockReturnValue(mql);
    return mql;
}

describe('useIsMobile', () => {
    const originalInnerWidth = window.innerWidth;

    afterEach(() => {
        Object.defineProperty(window, 'innerWidth', { value: originalInnerWidth, writable: true, configurable: true });
    });

    it('returns true when viewport is mobile width', () => {
        setupMobileViewport();
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(true);
    });

    it('returns false when viewport is desktop width', () => {
        setupDesktopViewport();
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(false);
    });

    it('updates when media query triggers change', () => {
        const mql = setupDesktopViewport();
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(false);

        act(() => {
            // Simulate resize to mobile
            Object.defineProperty(window, 'innerWidth', { value: 500, writable: true, configurable: true });
            mql.dispatchChange();
        });
        expect(result.current).toBe(true);
    });

    it('cleans up event listener on unmount', () => {
        const mql = setupDesktopViewport();
        const { unmount } = renderHook(() => useIsMobile());
        unmount();
        expect(mql.removeEventListener).toHaveBeenCalledWith('change', expect.any(Function));
    });
});

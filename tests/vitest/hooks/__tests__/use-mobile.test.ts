import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useIsMobile } from '@/hooks/use-mobile';

describe('useIsMobile', () => {
    let listeners: Array<() => void>;
    const originalMatchMedia = window.matchMedia;
    const originalInnerWidth = window.innerWidth;

    beforeEach(() => {
        listeners = [];
        window.matchMedia = vi.fn().mockImplementation(() => ({
            matches: window.innerWidth < 768,
            addEventListener: (_event: string, cb: () => void) => {
                listeners.push(cb);
            },
            removeEventListener: vi.fn(),
        }));
    });

    afterEach(() => {
        window.matchMedia = originalMatchMedia;
        window.innerWidth = originalInnerWidth;
    });

    it('returns true when viewport is mobile width', () => {
        window.innerWidth = 500;
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(true);
    });

    it('returns false when viewport is desktop width', () => {
        window.innerWidth = 1024;
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(false);
    });

    it('updates when media query triggers change', () => {
        window.innerWidth = 1024;
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(false);

        act(() => {
            window.innerWidth = 500;
            listeners.forEach((fn) => fn());
        });
        expect(result.current).toBe(true);
    });

    it('cleans up event listener on unmount', () => {
        window.innerWidth = 1024;
        const { unmount } = renderHook(() => useIsMobile());
        unmount();
        const mql = (window.matchMedia as ReturnType<typeof vi.fn>).mock.results[0].value;
        expect(mql.removeEventListener).toHaveBeenCalledWith('change', expect.any(Function));
    });
});

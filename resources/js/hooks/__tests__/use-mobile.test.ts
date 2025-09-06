import { renderHook, act } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useIsMobile } from '../use-mobile';

describe('useIsMobile', () => {
    let listeners: Array<() => void>;

    beforeEach(() => {
        listeners = [];
        window.matchMedia = vi.fn().mockImplementation(() => ({
            matches: window.innerWidth < 768,
            addEventListener: (_: string, listener: () => void) => {
                listeners.push(listener);
            },
            removeEventListener: (_: string, listener: () => void) => {
                listeners = listeners.filter((l) => l !== listener);
            },
        }));
    });

    it('returns true when width below breakpoint', () => {
        window.innerWidth = 500;
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(true);
    });

    it('updates when window width changes', () => {
        window.innerWidth = 900;
        const { result } = renderHook(() => useIsMobile());
        expect(result.current).toBe(false);
        act(() => {
            window.innerWidth = 600;
            listeners.forEach((fn) => fn());
        });
        expect(result.current).toBe(true);
    });
});

import '@testing-library/jest-dom/vitest';

import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useSystemDarkMode } from '@/pages/LandingPages/hooks/useSystemDarkMode';

describe('useSystemDarkMode', () => {
    let listeners: Array<() => void>;
    let matches: boolean;

    beforeEach(() => {
        listeners = [];
        matches = false;
        document.documentElement.classList.remove('dark');

        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: vi.fn().mockImplementation((query: string) => ({
                matches,
                media: query,
                addEventListener: (_event: string, handler: () => void) => {
                    listeners.push(handler);
                },
                removeEventListener: (_event: string, handler: () => void) => {
                    listeners = listeners.filter((l) => l !== handler);
                },
            })),
        });
    });

    afterEach(() => {
        listeners = [];
        document.documentElement.classList.remove('dark');
    });

    it('returns false when system prefers light mode', () => {
        matches = false;
        const { result } = renderHook(() => useSystemDarkMode());
        expect(result.current).toBe(false);
    });

    it('returns true when system prefers dark mode', () => {
        matches = true;
        const { result } = renderHook(() => useSystemDarkMode());
        expect(result.current).toBe(true);
    });

    it('adds dark class to documentElement when dark mode is active', () => {
        matches = true;
        renderHook(() => useSystemDarkMode());
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('does not add dark class when light mode is active', () => {
        matches = false;
        renderHook(() => useSystemDarkMode());
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('removes dark class on unmount', () => {
        matches = true;
        const { unmount } = renderHook(() => useSystemDarkMode());
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        unmount();
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('updates when system preference changes', () => {
        matches = false;
        const { result } = renderHook(() => useSystemDarkMode());
        expect(result.current).toBe(false);
        expect(document.documentElement.classList.contains('dark')).toBe(false);

        // Simulate preference change to dark
        matches = true;
        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: vi.fn().mockImplementation((query: string) => ({
                matches: true,
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
            })),
        });

        act(() => {
            listeners.forEach((l) => l());
        });

        expect(result.current).toBe(true);
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });
});

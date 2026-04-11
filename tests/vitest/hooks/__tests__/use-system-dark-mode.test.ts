import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { usePrefersDarkMode, useSystemDarkMode } from '@/pages/LandingPages/hooks/useSystemDarkMode';

describe('useSystemDarkMode', () => {
    let listeners: Array<() => void>;
    let matches: boolean;

    beforeEach(() => {
        listeners = [];
        matches = false;
        document.documentElement.classList.remove('dark');
        document.documentElement.style.colorScheme = '';

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
        document.documentElement.style.colorScheme = '';
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

    it('sets colorScheme to dark when dark mode is active', () => {
        matches = true;
        renderHook(() => useSystemDarkMode());
        expect(document.documentElement.style.colorScheme).toBe('dark');
    });

    it('sets colorScheme to light when light mode is active', () => {
        matches = false;
        renderHook(() => useSystemDarkMode());
        expect(document.documentElement.style.colorScheme).toBe('light');
    });

    it('restores previous dark class and colorScheme on unmount', () => {
        // Simulate a pre-existing dark state (e.g. set by useAppearance in the main app)
        document.documentElement.classList.add('dark');
        document.documentElement.style.colorScheme = 'dark';

        matches = true;
        const { unmount } = renderHook(() => useSystemDarkMode());
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.style.colorScheme).toBe('dark');

        unmount();
        // Should restore the captured pre-existing state, not force-remove
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.style.colorScheme).toBe('dark');
    });

    it('restores previous light state on unmount when it was light before', () => {
        // No pre-existing dark state
        document.documentElement.classList.remove('dark');
        document.documentElement.style.colorScheme = '';

        matches = true;
        const { unmount } = renderHook(() => useSystemDarkMode());
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        unmount();
        // Should restore the original light state
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.style.colorScheme).toBe('');
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
        expect(document.documentElement.style.colorScheme).toBe('dark');
    });
});

describe('usePrefersDarkMode', () => {
    let matches: boolean;

    beforeEach(() => {
        matches = false;

        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: vi.fn().mockImplementation((query: string) => ({
                matches,
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
            })),
        });
    });

    it('returns false when system prefers light mode', () => {
        matches = false;
        const { result } = renderHook(() => usePrefersDarkMode());
        expect(result.current).toBe(false);
    });

    it('returns true when system prefers dark mode', () => {
        matches = true;
        const { result } = renderHook(() => usePrefersDarkMode());
        expect(result.current).toBe(true);
    });

    it('does not mutate document.documentElement', () => {
        matches = true;
        document.documentElement.classList.remove('dark');
        document.documentElement.style.colorScheme = '';

        renderHook(() => usePrefersDarkMode());

        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.style.colorScheme).toBe('');
    });
});

import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { initializeTheme, useAppearance } from '@/hooks/use-appearance';

const matchMediaMock = vi.fn().mockImplementation((query) => ({
    matches: false,
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
}));

beforeEach(() => {
    Object.defineProperty(window, 'matchMedia', {
        value: matchMediaMock,
        writable: true,
    });
    document.cookie = '';
    localStorage.clear();
    document.documentElement.className = '';
    document.documentElement.style.colorScheme = '';
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('initializeTheme', () => {
    it('applies system theme by default', () => {
        initializeTheme();
        expect(document.documentElement.style.colorScheme).toBeTruthy();
    });

    it('applies saved light theme', () => {
        localStorage.setItem('appearance', 'light');
        initializeTheme();
        expect(document.documentElement.classList.contains('dark')).toBe(false);
        expect(document.documentElement.style.colorScheme).toBe('light');
    });

    it('applies saved dark theme', () => {
        localStorage.setItem('appearance', 'dark');
        initializeTheme();
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.style.colorScheme).toBe('dark');
    });
});

describe('useAppearance', () => {
    it('returns default system appearance', () => {
        const { result } = renderHook(() => useAppearance());
        expect(['system', 'light', 'dark']).toContain(result.current.appearance);
    });

    it('provides updateAppearance function', () => {
        const { result } = renderHook(() => useAppearance());
        expect(typeof result.current.updateAppearance).toBe('function');
    });

    it('updates appearance and applies theme', () => {
        const { result } = renderHook(() => useAppearance());

        act(() => result.current.updateAppearance('dark'));

        expect(result.current.appearance).toBe('dark');
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.cookie).toContain('appearance=dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.style.colorScheme).toBe('dark');
    });

    it('removes dark class when set to light', () => {
        document.documentElement.classList.add('dark');
        const { result } = renderHook(() => useAppearance());
        act(() => result.current.updateAppearance('light'));
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });
});

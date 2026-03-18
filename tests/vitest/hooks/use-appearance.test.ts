import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { initializeTheme, useAppearance } from '@/hooks/use-appearance';

describe('initializeTheme', () => {
    beforeEach(() => {
        localStorage.clear();
        document.documentElement.classList.remove('dark');
        document.documentElement.style.colorScheme = '';
    });

    it('applies system theme by default', () => {
        initializeTheme();
        // Just verify it doesn't throw
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
    beforeEach(() => {
        localStorage.clear();
        document.documentElement.classList.remove('dark');
        document.documentElement.style.colorScheme = '';
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('returns default system appearance', () => {
        const { result } = renderHook(() => useAppearance());
        // Initially 'system', then updated in useEffect
        expect(['system', 'light', 'dark']).toContain(result.current.appearance);
    });

    it('provides updateAppearance function', () => {
        const { result } = renderHook(() => useAppearance());
        expect(typeof result.current.updateAppearance).toBe('function');
    });

    it('persists appearance to localStorage', () => {
        const { result } = renderHook(() => useAppearance());
        act(() => result.current.updateAppearance('dark'));
        expect(localStorage.getItem('appearance')).toBe('dark');
    });

    it('applies dark class when set to dark', () => {
        const { result } = renderHook(() => useAppearance());
        act(() => result.current.updateAppearance('dark'));
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('removes dark class when set to light', () => {
        document.documentElement.classList.add('dark');
        const { result } = renderHook(() => useAppearance());
        act(() => result.current.updateAppearance('light'));
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });
});

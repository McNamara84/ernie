import { renderHook, act } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAppearance, initializeTheme } from '../use-appearance';

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

describe('useAppearance', () => {
    it('updates appearance and applies theme', () => {
        const { result } = renderHook(() => useAppearance());

        act(() => result.current.updateAppearance('dark'));

        expect(result.current.appearance).toBe('dark');
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(document.cookie).toContain('appearance=dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.style.colorScheme).toBe('dark');
    });

    it('initializes theme from localStorage', () => {
        localStorage.setItem('appearance', 'dark');
        initializeTheme();
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });
});

import '@testing-library/jest-dom/vitest';

import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useReducedMotion } from '@/hooks/use-reduced-motion';

describe('useReducedMotion', () => {
    let listeners: Array<() => void>;
    let matches: boolean;

    beforeEach(() => {
        listeners = [];
        matches = false;

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
    });

    it('returns false when motion is not reduced', () => {
        matches = false;
        const { result } = renderHook(() => useReducedMotion());
        expect(result.current).toBe(false);
    });

    it('returns true when user prefers reduced motion', () => {
        matches = true;
        const { result } = renderHook(() => useReducedMotion());
        expect(result.current).toBe(true);
    });

    it('updates when the OS preference changes', () => {
        matches = false;
        const { result } = renderHook(() => useReducedMotion());
        expect(result.current).toBe(false);

        // Simulate OS preference change
        matches = true;
        // Re-mock matchMedia to return new value
        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: vi.fn().mockImplementation((query: string) => ({
                matches: true,
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
            })),
        });

        // Trigger listeners
        act(() => {
            listeners.forEach((l) => l());
        });

        expect(result.current).toBe(true);
    });

    it('cleans up listener on unmount', () => {
        const removeListener = vi.fn();
        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: vi.fn().mockImplementation((query: string) => ({
                matches: false,
                media: query,
                addEventListener: vi.fn(),
                removeEventListener: removeListener,
            })),
        });

        const { unmount } = renderHook(() => useReducedMotion());
        unmount();

        expect(removeListener).toHaveBeenCalled();
    });
});

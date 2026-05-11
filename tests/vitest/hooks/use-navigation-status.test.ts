import '@testing-library/jest-dom/vitest';

import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useNavigationStatus } from '@/hooks/use-navigation-status';

type RouterEventHandler = (event: { detail: { visit: { showProgress: boolean } } }) => void;

const handlers = vi.hoisted(() => ({
    start: [] as RouterEventHandler[],
    finish: [] as RouterEventHandler[],
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        on: (event: 'start' | 'finish', handler: RouterEventHandler) => {
            handlers[event].push(handler);
            return () => {
                handlers[event] = handlers[event].filter((registeredHandler) => registeredHandler !== handler);
            };
        },
    },
}));

describe('useNavigationStatus', () => {
    beforeEach(() => {
        handlers.start = [];
        handlers.finish = [];
    });

    it('returns ready by default', () => {
        const { result } = renderHook(() => useNavigationStatus('Dashboard'));

        expect(result.current.isNavigating).toBe(false);
        expect(result.current.statusText).toBe('Ready');
    });

    it('switches to navigating state for showProgress visits and resets on finish', () => {
        const { result } = renderHook(() => useNavigationStatus('Resources'));

        act(() => {
            handlers.start[0]?.({ detail: { visit: { showProgress: true } } });
        });

        expect(result.current.isNavigating).toBe(true);
        expect(result.current.statusText).toBe('Opening Resources...');

        act(() => {
            handlers.finish[0]?.({ detail: { visit: { showProgress: true } } });
        });

        expect(result.current.isNavigating).toBe(false);
        expect(result.current.statusText).toBe('Ready');
    });

    it('ignores visits without visible progress', () => {
        const { result } = renderHook(() => useNavigationStatus('Editor'));

        act(() => {
            handlers.start[0]?.({ detail: { visit: { showProgress: false } } });
        });

        expect(result.current.isNavigating).toBe(false);
        expect(result.current.statusText).toBe('Ready');
    });
});
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { useDebounce } from '@/hooks/use-debounce';

describe('useDebounce', () => {
    it('returns initial value immediately', () => {
        const { result } = renderHook(() => useDebounce('hello', 300));
        expect(result.current).toBe('hello');
    });

    it('does not update value before delay', () => {
        vi.useFakeTimers();
        const { result, rerender } = renderHook(({ value }) => useDebounce(value, 300), {
            initialProps: { value: 'initial' },
        });

        rerender({ value: 'updated' });
        expect(result.current).toBe('initial');

        vi.advanceTimersByTime(200);
        expect(result.current).toBe('initial');

        vi.useRealTimers();
    });

    it('updates value after delay', () => {
        vi.useFakeTimers();
        const { result, rerender } = renderHook(({ value }) => useDebounce(value, 300), {
            initialProps: { value: 'initial' },
        });

        rerender({ value: 'updated' });

        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(result.current).toBe('updated');
        vi.useRealTimers();
    });

    it('uses default delay of 300ms', () => {
        vi.useFakeTimers();
        const { result, rerender } = renderHook(({ value }) => useDebounce(value), {
            initialProps: { value: 'a' },
        });

        rerender({ value: 'b' });

        act(() => {
            vi.advanceTimersByTime(299);
        });
        expect(result.current).toBe('a');

        act(() => {
            vi.advanceTimersByTime(1);
        });
        expect(result.current).toBe('b');

        vi.useRealTimers();
    });

    it('resets timer on rapid changes', () => {
        vi.useFakeTimers();
        const { result, rerender } = renderHook(({ value }) => useDebounce(value, 300), {
            initialProps: { value: 'a' },
        });

        rerender({ value: 'b' });
        act(() => {
            vi.advanceTimersByTime(200);
        });

        rerender({ value: 'c' });
        act(() => {
            vi.advanceTimersByTime(200);
        });

        // 'b' should be skipped, still showing 'a'
        expect(result.current).toBe('a');

        act(() => {
            vi.advanceTimersByTime(100);
        });

        // Now 300ms after 'c' was set
        expect(result.current).toBe('c');

        vi.useRealTimers();
    });
});

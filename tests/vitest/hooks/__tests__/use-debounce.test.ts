import { act, renderHook } from '@testing-library/react';
import { afterEach,beforeEach, describe, expect, it, vi } from 'vitest';

import { useDebounce } from '@/hooks/use-debounce';

describe('useDebounce', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('returns the initial value immediately', () => {
        const { result } = renderHook(() => useDebounce('initial', 300));
        expect(result.current).toBe('initial');
    });

    it('updates the value after the delay', () => {
        const { result, rerender } = renderHook(({ value }) => useDebounce(value, 300), {
            initialProps: { value: 'initial' },
        });

        expect(result.current).toBe('initial');

        rerender({ value: 'updated' });

        // Value should still be initial before delay
        expect(result.current).toBe('initial');

        // Fast-forward time past the delay
        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(result.current).toBe('updated');
    });

    it('resets the timer when value changes before delay expires', () => {
        const { result, rerender } = renderHook(({ value }) => useDebounce(value, 300), {
            initialProps: { value: 'first' },
        });

        rerender({ value: 'second' });

        // Advance 200ms (less than 300ms delay)
        act(() => {
            vi.advanceTimersByTime(200);
        });

        expect(result.current).toBe('first');

        // Change value again, this should reset the timer
        rerender({ value: 'third' });

        // Advance another 200ms - still shouldn't update since timer was reset
        act(() => {
            vi.advanceTimersByTime(200);
        });

        expect(result.current).toBe('first');

        // Complete the remaining 100ms
        act(() => {
            vi.advanceTimersByTime(100);
        });

        expect(result.current).toBe('third');
    });

    it('uses default delay of 300ms when not specified', () => {
        const { result, rerender } = renderHook(({ value }) => useDebounce(value), {
            initialProps: { value: 'initial' },
        });

        rerender({ value: 'updated' });

        // Should not update before 300ms
        act(() => {
            vi.advanceTimersByTime(299);
        });

        expect(result.current).toBe('initial');

        // Should update at exactly 300ms
        act(() => {
            vi.advanceTimersByTime(1);
        });

        expect(result.current).toBe('updated');
    });

    it('works with different types', () => {
        const { result, rerender } = renderHook(({ value }) => useDebounce(value, 100), {
            initialProps: { value: 42 },
        });

        expect(result.current).toBe(42);

        rerender({ value: 100 });

        act(() => {
            vi.advanceTimersByTime(100);
        });

        expect(result.current).toBe(100);
    });

    it('works with objects', () => {
        const initialObj = { name: 'test' };
        const updatedObj = { name: 'updated' };

        const { result, rerender } = renderHook(({ value }) => useDebounce(value, 100), {
            initialProps: { value: initialObj },
        });

        expect(result.current).toEqual(initialObj);

        rerender({ value: updatedObj });

        act(() => {
            vi.advanceTimersByTime(100);
        });

        expect(result.current).toEqual(updatedObj);
    });
});

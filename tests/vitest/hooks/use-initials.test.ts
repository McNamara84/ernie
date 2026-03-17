import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useInitials } from '@/hooks/use-initials';

describe('useInitials', () => {
    it('returns first and last initials for full name', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('John Doe')).toBe('JD');
    });

    it('returns single initial for single name', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('Alice')).toBe('A');
    });

    it('returns first and last for multi-part name', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('John Michael Doe')).toBe('JD');
    });

    it('returns empty for empty string', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('')).toBe('');
    });

    it('uppercases initials', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('jane doe')).toBe('JD');
    });

    it('handles whitespace', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('  John  Doe  ')).toBe('JD');
    });

    it('returns stable callback reference', () => {
        const { result, rerender } = renderHook(() => useInitials());
        const first = result.current;
        rerender();
        expect(result.current).toBe(first);
    });
});

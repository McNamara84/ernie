import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useInitials } from '@/hooks/use-initials';

describe('useInitials', () => {
    it('returns empty string for empty input', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('')).toBe('');
    });

    it('returns first letter for single name', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('alice')).toBe('A');
    });

    it('returns first and last initials for full name', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('John Doe')).toBe('JD');
    });

    it('returns first and last for multi-part name', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('John Ronald Reuel Tolkien')).toBe('JT');
    });

    it('uppercases initials', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('jane doe')).toBe('JD');
    });

    it('handles whitespace', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('  John  Doe  ')).toBe('JD');
    });

    it('handles hyphenated names with extra spaces', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('  Mary-Jane   Watson  ')).toBe('MW');
    });

    it('returns stable callback reference', () => {
        const { result, rerender } = renderHook(() => useInitials());
        const first = result.current;
        rerender();
        expect(result.current).toBe(first);
    });
});

import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useInitials } from '../use-initials';

describe('useInitials', () => {
    it('returns empty string for empty input', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('')).toBe('');
    });

    it('returns first letter for single name', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('alice')).toBe('A');
    });

    it('returns initials for multiple names', () => {
        const { result } = renderHook(() => useInitials());
        expect(result.current('John Ronald Reuel Tolkien')).toBe('JT');
    });
});

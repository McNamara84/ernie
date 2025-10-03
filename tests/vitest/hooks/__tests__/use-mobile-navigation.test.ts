import { renderHook, act } from '@testing-library/react';
import { describe, it, beforeEach, expect } from 'vitest';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';

describe('useMobileNavigation', () => {
    beforeEach(() => {
        document.body.style.pointerEvents = 'none';
    });

    it('removes pointer-events style from body', () => {
        const { result } = renderHook(() => useMobileNavigation());
        act(() => result.current());
        expect(document.body.style.pointerEvents).toBe('');
    });
});


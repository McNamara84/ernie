import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useMobileNavigation } from '@/hooks/use-mobile-navigation';

describe('useMobileNavigation', () => {
    it('returns a stable callback', () => {
        const { result, rerender } = renderHook(() => useMobileNavigation());

        const first = result.current;
        rerender();
        const second = result.current;

        expect(first).toBe(second);
    });

    it('removes pointer-events style from body when called', () => {
        document.body.style.pointerEvents = 'none';

        const { result } = renderHook(() => useMobileNavigation());

        act(() => {
            result.current();
        });

        expect(document.body.style.pointerEvents).toBe('');
    });
});

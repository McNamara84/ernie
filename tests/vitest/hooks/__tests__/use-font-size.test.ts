import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { initializeFontSize, useFontSize } from '@/hooks/use-font-size';

// Hoisted mocks to avoid initialization issues
const mocks = vi.hoisted(() => ({
    routerPut: vi.fn(),
    usePage: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        put: mocks.routerPut,
    },
    usePage: () => mocks.usePage(),
}));

describe('use-font-size', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        // Reset document class
        document.documentElement.classList.remove('font-large');
        // Default mock
        mocks.usePage.mockReturnValue({
            props: {
                fontSizePreference: 'regular',
            },
        });
    });

    afterEach(() => {
        document.documentElement.classList.remove('font-large');
    });

    describe('initializeFontSize', () => {
        it('adds font-large class when fontSize is large', () => {
            initializeFontSize('large');
            expect(document.documentElement.classList.contains('font-large')).toBe(true);
        });

        it('removes font-large class when fontSize is normal', () => {
            document.documentElement.classList.add('font-large');
            initializeFontSize('regular');
            expect(document.documentElement.classList.contains('font-large')).toBe(false);
        });

        it('handles toggle correctly - adds class when not present', () => {
            expect(document.documentElement.classList.contains('font-large')).toBe(false);
            initializeFontSize('large');
            expect(document.documentElement.classList.contains('font-large')).toBe(true);
        });

        it('handles toggle correctly - removes class when present', () => {
            document.documentElement.classList.add('font-large');
            initializeFontSize('regular');
            expect(document.documentElement.classList.contains('font-large')).toBe(false);
        });
    });

    describe('useFontSize', () => {
        it('returns initial fontSize from page props', () => {
            mocks.usePage.mockReturnValue({
                props: {
                    fontSizePreference: 'regular',
                },
            });

            const { result } = renderHook(() => useFontSize());

            expect(result.current.fontSize).toBe('regular');
        });

        it('returns large fontSize when preference is large', () => {
            mocks.usePage.mockReturnValue({
                props: {
                    fontSizePreference: 'large',
                },
            });

            const { result } = renderHook(() => useFontSize());

            expect(result.current.fontSize).toBe('large');
        });

        it('provides updateFontSize function', () => {
            const { result } = renderHook(() => useFontSize());

            expect(typeof result.current.updateFontSize).toBe('function');
        });

        it('updateFontSize updates the fontSize state to large', () => {
            const { result } = renderHook(() => useFontSize());

            act(() => {
                result.current.updateFontSize('large');
            });

            expect(result.current.fontSize).toBe('large');
        });

        it('updateFontSize updates the fontSize state to normal', () => {
            mocks.usePage.mockReturnValue({
                props: {
                    fontSizePreference: 'large',
                },
            });

            const { result } = renderHook(() => useFontSize());
            act(() => {
                result.current.updateFontSize('regular');
            });

            expect(result.current.fontSize).toBe('regular');
        });

        it('updateFontSize adds font-large class to document when size is large', () => {
            const { result } = renderHook(() => useFontSize());

            act(() => {
                result.current.updateFontSize('large');
            });

            expect(document.documentElement.classList.contains('font-large')).toBe(true);
        });

        it('updateFontSize removes font-large class from document when size is normal', () => {
            document.documentElement.classList.add('font-large');

            const { result } = renderHook(() => useFontSize());
            act(() => {
                result.current.updateFontSize('regular');
            });

            expect(document.documentElement.classList.contains('font-large')).toBe(false);
        });

        it('updateFontSize calls router.put to persist preference', () => {
            const { result } = renderHook(() => useFontSize());

            act(() => {
                result.current.updateFontSize('large');
            });

            expect(mocks.routerPut).toHaveBeenCalledWith(
                '/settings/font-size',
                { font_size_preference: 'large' },
                { preserveState: true, preserveScroll: true },
            );
        });

        it('updateFontSize persists normal preference to backend', () => {
            const { result } = renderHook(() => useFontSize());

            act(() => {
                result.current.updateFontSize('regular');
            });

            expect(mocks.routerPut).toHaveBeenCalledWith(
                '/settings/font-size',
                { font_size_preference: 'regular' },
                { preserveState: true, preserveScroll: true },
            );
        });

        it('initializes font size on mount based on preference', () => {
            mocks.usePage.mockReturnValue({
                props: {
                    fontSizePreference: 'large',
                },
            });

            renderHook(() => useFontSize());

            expect(document.documentElement.classList.contains('font-large')).toBe(true);
        });

        it('returns stable updateFontSize reference', () => {
            const { result, rerender } = renderHook(() => useFontSize());

            const firstReference = result.current.updateFontSize;
            rerender();
            const secondReference = result.current.updateFontSize;

            expect(firstReference).toBe(secondReference);
        });
    });
});

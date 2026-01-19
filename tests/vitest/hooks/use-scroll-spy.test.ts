import '@testing-library/jest-dom/vitest';

import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useScrollSpy } from '@/hooks/use-scroll-spy';

// Mock IntersectionObserver
class MockIntersectionObserver {
    callback: IntersectionObserverCallback;
    elements: Element[] = [];

    constructor(callback: IntersectionObserverCallback) {
        this.callback = callback;
    }

    observe(element: Element) {
        this.elements.push(element);
    }

    unobserve(element: Element) {
        this.elements = this.elements.filter((e) => e !== element);
    }

    disconnect() {
        this.elements = [];
    }

    // Simulate intersection
    simulateIntersection(entries: Partial<IntersectionObserverEntry>[]) {
        this.callback(
            entries.map((entry) => ({
                boundingClientRect: {} as DOMRectReadOnly,
                intersectionRatio: 0,
                intersectionRect: {} as DOMRectReadOnly,
                rootBounds: null,
                time: Date.now(),
                ...entry,
            })) as IntersectionObserverEntry[],
            this as unknown as IntersectionObserver,
        );
    }
}

describe('useScrollSpy', () => {
    let mockObserver: MockIntersectionObserver | null = null;

    beforeEach(() => {
        // Create mock elements in the document
        const section1 = document.createElement('div');
        section1.id = 'section-1';
        document.body.appendChild(section1);

        const section2 = document.createElement('div');
        section2.id = 'section-2';
        document.body.appendChild(section2);

        const section3 = document.createElement('div');
        section3.id = 'section-3';
        document.body.appendChild(section3);

        // Mock IntersectionObserver
        vi.stubGlobal('IntersectionObserver', function (callback: IntersectionObserverCallback) {
            mockObserver = new MockIntersectionObserver(callback);
            return mockObserver;
        });
    });

    afterEach(() => {
        // Clean up mock elements
        document.body.innerHTML = '';
        mockObserver = null;
        vi.unstubAllGlobals();
    });

    it('returns null initially when no sections are provided', () => {
        const { result } = renderHook(() => useScrollSpy([]));
        expect(result.current).toBeNull();
    });

    it('sets first section as active by default', () => {
        const { result } = renderHook(() => useScrollSpy(['section-1', 'section-2', 'section-3']));

        // Initial state should be the first section
        expect(result.current).toBe('section-1');
    });

    it('observes all sections', () => {
        renderHook(() => useScrollSpy(['section-1', 'section-2', 'section-3']));

        expect(mockObserver).not.toBeNull();
        expect(mockObserver!.elements).toHaveLength(3);
    });

    it('updates active section when intersection changes', () => {
        const { result } = renderHook(() => useScrollSpy(['section-1', 'section-2', 'section-3']));

        // Simulate section-2 becoming visible
        act(() => {
            mockObserver!.simulateIntersection([
                { target: document.getElementById('section-1')!, isIntersecting: false },
                { target: document.getElementById('section-2')!, isIntersecting: true },
                { target: document.getElementById('section-3')!, isIntersecting: false },
            ]);
        });

        expect(result.current).toBe('section-2');
    });

    it('handles missing elements gracefully', () => {
        // section-missing doesn't exist in DOM
        const { result } = renderHook(() => useScrollSpy(['section-1', 'section-missing', 'section-2']));

        // Should only observe existing elements
        expect(mockObserver!.elements).toHaveLength(2);
        expect(result.current).toBe('section-1');
    });

    it('disconnects observer on unmount', () => {
        const { unmount } = renderHook(() => useScrollSpy(['section-1', 'section-2']));

        const disconnectSpy = vi.spyOn(mockObserver!, 'disconnect');
        unmount();

        expect(disconnectSpy).toHaveBeenCalled();
    });

    it('selects first intersecting section in DOM order', () => {
        const { result } = renderHook(() => useScrollSpy(['section-1', 'section-2', 'section-3']));

        // Simulate multiple sections visible - should select first in DOM order
        act(() => {
            mockObserver!.simulateIntersection([
                { target: document.getElementById('section-1')!, isIntersecting: true },
                { target: document.getElementById('section-2')!, isIntersecting: true },
                { target: document.getElementById('section-3')!, isIntersecting: false },
            ]);
        });

        expect(result.current).toBe('section-1');
    });

    it('recreates observer when sectionIds change', () => {
        const { rerender } = renderHook(({ ids }) => useScrollSpy(ids), {
            initialProps: { ids: ['section-1', 'section-2'] },
        });

        const firstObserver = mockObserver;
        expect(firstObserver!.elements).toHaveLength(2);

        // Rerender with new section IDs
        rerender({ ids: ['section-1', 'section-2', 'section-3'] });

        // A new observer should be created
        expect(mockObserver!.elements).toHaveLength(3);
    });

    it('does not cause infinite loop when activeId changes', () => {
        // This test verifies the fix for the infinite loop issue
        // The hook should not re-run the effect when activeId changes
        const { result } = renderHook(() => useScrollSpy(['section-1', 'section-2']));

        // Simulate rapid intersection changes
        act(() => {
            mockObserver!.simulateIntersection([{ target: document.getElementById('section-1')!, isIntersecting: true }]);
        });

        act(() => {
            mockObserver!.simulateIntersection([{ target: document.getElementById('section-2')!, isIntersecting: true }]);
        });

        // Should have settled on section-1 (first in order)
        expect(result.current).toBe('section-1');
    });
});

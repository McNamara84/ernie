/**
 * @vitest-environment jsdom
 */

import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useFadeInOnScroll } from '@/pages/LandingPages/hooks/useFadeInOnScroll';

// Mock use-reduced-motion hook
vi.mock('@/hooks/use-reduced-motion', () => ({
    useReducedMotion: vi.fn(() => false),
}));

import { useReducedMotion } from '@/hooks/use-reduced-motion';

/**
 * Test wrapper that attaches the ref to an actual DOM element,
 * which is required for IntersectionObserver to be created.
 */
function TestComponent({ threshold }: { threshold?: number } = {}) {
    const { ref, isVisible } = useFadeInOnScroll({ threshold });
    return (
        <div ref={ref} data-testid="target" data-visible={String(isVisible)}>
            {isVisible ? 'visible' : 'hidden'}
        </div>
    );
}

describe('useFadeInOnScroll', () => {
    beforeEach(() => {
        vi.mocked(useReducedMotion).mockReturnValue(false);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('returns hidden initially before observer triggers', () => {
        // Override the global mock so the observer does NOT auto-trigger
        const originalIO = globalThis.IntersectionObserver;
        globalThis.IntersectionObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        } as unknown as typeof IntersectionObserver;

        render(<TestComponent />);
        expect(screen.getByTestId('target')).toHaveAttribute('data-visible', 'false');

        globalThis.IntersectionObserver = originalIO;
    });

    it('sets isVisible to true when IntersectionObserver triggers (mock auto-triggers)', () => {
        // The global mock IntersectionObserver in vitest.setup.ts immediately
        // triggers isIntersecting: true, so isVisible should be true after render
        render(<TestComponent />);
        expect(screen.getByTestId('target')).toHaveAttribute('data-visible', 'true');
    });

    it('respects reduced motion by setting isVisible true immediately', () => {
        vi.mocked(useReducedMotion).mockReturnValue(true);

        render(<TestComponent />);
        expect(screen.getByTestId('target')).toHaveAttribute('data-visible', 'true');
    });

    it('accepts a custom threshold option', () => {
        render(<TestComponent threshold={0.5} />);
        expect(screen.getByTestId('target')).toHaveAttribute('data-visible', 'true');
    });

    it('handles missing ref element gracefully', () => {
        // Render just the hook without attaching ref to a DOM element
        function NoRefComponent() {
            const { isVisible } = useFadeInOnScroll();
            return <span data-testid="no-ref">{isVisible ? 'visible' : 'hidden'}</span>;
        }

        render(<NoRefComponent />);
        expect(screen.getByTestId('no-ref')).toHaveTextContent('hidden');
    });
});

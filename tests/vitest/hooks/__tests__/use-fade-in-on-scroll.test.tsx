/**
 * @vitest-environment jsdom
 */

import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { _resetJsFadeReady, useFadeInOnScroll } from '@/pages/LandingPages/hooks/useFadeInOnScroll';

// Mock use-reduced-motion hook
vi.mock('@/hooks/use-reduced-motion', () => ({
    useReducedMotion: vi.fn(() => false),
}));

import { useReducedMotion } from '@/hooks/use-reduced-motion';

/**
 * Test wrapper that attaches the callback ref to an actual DOM element.
 * The new API returns a callback ref (element => void) instead of { ref, isVisible }.
 */
function TestComponent({ threshold }: { threshold?: number } = {}) {
    const fadeRef = useFadeInOnScroll({ threshold });
    return (
        <div ref={fadeRef} data-testid="target" className="fade-in-on-scroll">
            content
        </div>
    );
}

describe('useFadeInOnScroll', () => {
    beforeEach(() => {
        vi.mocked(useReducedMotion).mockReturnValue(false);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.documentElement.classList.remove('js-fade-ready');
        _resetJsFadeReady();
    });

    it('does not add is-visible class before observer triggers', () => {
        // Override the global mock so the observer does NOT auto-trigger
        const originalIO = globalThis.IntersectionObserver;
        globalThis.IntersectionObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        } as unknown as typeof IntersectionObserver;

        render(<TestComponent />);
        expect(screen.getByTestId('target')).not.toHaveClass('is-visible');

        globalThis.IntersectionObserver = originalIO;
    });

    it('adds is-visible class when IntersectionObserver triggers', () => {
        // The global mock IntersectionObserver in vitest.setup.ts immediately
        // triggers isIntersecting: true, so is-visible class should be added
        render(<TestComponent />);
        expect(screen.getByTestId('target')).toHaveClass('is-visible');
    });

    it('respects reduced motion by adding is-visible immediately', () => {
        vi.mocked(useReducedMotion).mockReturnValue(true);

        render(<TestComponent />);
        expect(screen.getByTestId('target')).toHaveClass('is-visible');
    });

    it('accepts a custom threshold option', () => {
        render(<TestComponent threshold={0.5} />);
        expect(screen.getByTestId('target')).toHaveClass('is-visible');
    });

    it('handles null ref element gracefully', () => {
        function NoRefComponent() {
            const fadeRef = useFadeInOnScroll();
            void fadeRef;
            return <span data-testid="no-ref">content</span>;
        }

        render(<NoRefComponent />);
        expect(screen.getByTestId('no-ref')).toBeInTheDocument();
    });

    it('adds js-fade-ready class to the document element', () => {
        expect(document.documentElement).not.toHaveClass('js-fade-ready');
        render(<TestComponent />);
        expect(document.documentElement).toHaveClass('js-fade-ready');
    });
});

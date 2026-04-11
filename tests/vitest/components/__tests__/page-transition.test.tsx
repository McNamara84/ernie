import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Override global mock from vitest.setup.ts to test the actual component
vi.unmock('@/components/page-transition');

vi.mock('@inertiajs/react', () => ({
    usePage: vi.fn(() => ({ url: '/test-page' })),
}));

vi.mock('@/hooks/use-reduced-motion', () => ({
    useReducedMotion: vi.fn(() => false),
}));

import { PageTransition } from '@/components/page-transition';
import { useReducedMotion } from '@/hooks/use-reduced-motion';

describe('PageTransition', () => {
    it('renders children', () => {
        render(
            <PageTransition>
                <div>Page content</div>
            </PageTransition>,
        );
        expect(screen.getByText('Page content')).toBeInTheDocument();
    });

    it('wraps content in a motion div when motion is allowed', () => {
        vi.mocked(useReducedMotion).mockReturnValue(false);

        const { container } = render(
            <PageTransition>
                <div>Animated</div>
            </PageTransition>,
        );

        expect(container.querySelector('[data-slot="page-transition"]')).toBeInTheDocument();
    });

    it('does not wrap in motion div when reduced motion is preferred', () => {
        vi.mocked(useReducedMotion).mockReturnValue(true);

        const { container } = render(
            <PageTransition>
                <div>Static</div>
            </PageTransition>,
        );

        expect(container.querySelector('[data-slot="page-transition"]')).not.toBeInTheDocument();
        expect(screen.getByText('Static')).toBeInTheDocument();
    });
});

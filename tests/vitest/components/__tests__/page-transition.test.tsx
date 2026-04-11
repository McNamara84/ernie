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

    it('renders a plain div wrapper when reduced motion is preferred', () => {
        vi.mocked(useReducedMotion).mockReturnValue(true);

        const { container } = render(
            <PageTransition>
                <div>Static</div>
            </PageTransition>,
        );

        const wrapper = container.querySelector('[data-slot="page-transition"]');
        expect(wrapper).toBeInTheDocument();
        expect(wrapper?.tagName).toBe('DIV');
        expect(wrapper).toHaveClass('flex', 'flex-1', 'flex-col', 'min-h-0');
        expect(screen.getByText('Static')).toBeInTheDocument();
    });
});

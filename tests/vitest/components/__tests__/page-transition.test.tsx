import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Override global mock from vitest.setup.ts to test the actual component
vi.unmock('@/components/page-transition');

const mockPage = vi.hoisted(() => ({ url: '/test-page' }));

vi.mock('@inertiajs/react', () => ({ usePage: () => mockPage }));

vi.mock('@/hooks/use-reduced-motion', () => ({
    useReducedMotion: vi.fn(() => false),
}));

import { PageTransition } from '@/components/page-transition';
import { useReducedMotion } from '@/hooks/use-reduced-motion';

describe('PageTransition', () => {
    beforeEach(() => {
        mockPage.url = '/test-page';
    });

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

    it('preserves editor content when a newly saved draft gets its resource URL', () => {
        mockPage.url = '/editor';
        vi.mocked(useReducedMotion).mockReturnValue(false);
        function EditorInput() {
            const [value, setValue] = useState('');
            return <input aria-label="Title" value={value} onChange={(event) => setValue(event.target.value)} />;
        }

        const view = render(
            <PageTransition>
                <EditorInput />
            </PageTransition>,
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Title' }), { target: { value: 'Saved draft' } });

        mockPage.url = '/editor?resourceId=42';
        view.rerender(
            <PageTransition>
                <EditorInput />
            </PageTransition>,
        );

        expect(screen.getByRole('textbox', { name: 'Title' })).toHaveValue('Saved draft');
    });
});

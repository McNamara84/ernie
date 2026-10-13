import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Override global mock from vitest.setup.ts to test the actual component
vi.unmock('@/components/page-transition');

const mockPage = vi.hoisted(() => ({
    url: '/test-page',
    props: {} as { draftSaveTransition?: { fromUrl: string; resourceId: string } },
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => mockPage }));

vi.mock('@/hooks/use-reduced-motion', () => ({
    useReducedMotion: vi.fn(() => false),
}));

import { PageTransition, pageTransitionKey } from '@/components/page-transition';
import { useReducedMotion } from '@/hooks/use-reduced-motion';

describe('PageTransition', () => {
    beforeEach(() => {
        mockPage.url = '/test-page';
        mockPage.props = {};
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
        mockPage.props = { draftSaveTransition: { fromUrl: '/editor', resourceId: '42' } };
        view.rerender(
            <PageTransition>
                <EditorInput />
            </PageTransition>,
        );

        expect(screen.getByRole('textbox', { name: 'Title' })).toHaveValue('Saved draft');
    });

    it('gives different saved resources distinct transition keys', () => {
        const draftSaveTransition = { fromUrl: '/editor', resourceId: '42' };

        expect(pageTransitionKey('/editor?resourceId=42', draftSaveTransition)).toBe('/editor');
        expect(pageTransitionKey('/editor?resourceId=43', draftSaveTransition)).toBe('/editor?resourceId=43');
        expect(pageTransitionKey('/editor?resourceId=42')).toBe('/editor?resourceId=42');
        expect(pageTransitionKey('/editor?resourceId=42', { fromUrl: '/editor?xmlSession=upload', resourceId: '42' })).toBe(
            '/editor?xmlSession=upload',
        );
    });

    it('remounts editor content when the resource ID changes', async () => {
        mockPage.url = '/editor?resourceId=42';
        mockPage.props = { draftSaveTransition: { fromUrl: '/editor', resourceId: '42' } };
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
        fireEvent.change(screen.getByRole('textbox', { name: 'Title' }), { target: { value: 'Resource A' } });

        mockPage.url = '/editor?resourceId=43';
        view.rerender(
            <PageTransition>
                <EditorInput />
            </PageTransition>,
        );

        await waitFor(() => expect(screen.getByRole('textbox', { name: 'Title' })).toHaveValue(''));
    });
});

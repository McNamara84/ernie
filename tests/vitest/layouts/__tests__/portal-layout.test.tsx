import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.unmock('@/components/page-transition');

const mockPage = vi.hoisted(() => ({ url: '/doi-search', props: {} }));

vi.mock('@/hooks/use-reduced-motion', () => ({ useReducedMotion: () => false }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => mockPage,
    Link: ({ href, children, ...props }: { href: unknown; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => {
        const resolvedHref = typeof href === 'string'
            ? href
            : href && typeof href === 'object' && 'url' in (href as Record<string, unknown>)
                ? String((href as { url: string }).url)
                : '';
        return <a href={resolvedHref} {...props}>{children}</a>;
    },
}));

vi.mock('@/components/portal/PortalHeader', () => ({
    PortalHeader: () => <header data-testid="portal-header">GFZ Data Services Portal</header>,
}));

vi.mock('@/components/app-footer', () => ({
    AppFooter: () => <footer data-testid="app-footer">Footer</footer>,
}));

import PortalLayout from '@/layouts/portal-layout';

describe('PortalLayout', () => {
    beforeEach(() => {
        mockPage.url = '/doi-search';
    });

    it.each([
        { portalKind: 'doi' as const, path: '/doi-search' },
        { portalKind: 'igsn' as const, path: '/igsn-search' },
    ])('keeps the $portalKind workspace interactive across filter and pagination changes', async ({ portalKind, path }) => {
        mockPage.url = path;
        const workspace = (label: string) => (
            <PortalLayout portalKind={portalKind}>
                <input aria-label="Search draft" defaultValue="something" />
                <p>{label}</p>
            </PortalLayout>
        );
        const view = render(workspace('Initial results'));
        const input = screen.getByRole('textbox', { name: 'Search draft' });
        input.focus();
        fireEvent.change(input, { target: { value: 'Draft to keep' } });

        for (const [query, label] of [
            ['?q=something', 'Filtered results'],
            ['?q=something&page=2', 'Next results page'],
        ]) {
            mockPage.url = path + query;
            view.rerender(workspace(label));

            await waitFor(() => expect(screen.getByText(label)).toBeVisible());
            expect(screen.getByRole('textbox', { name: 'Search draft' })).toBe(input);
            expect(input).toHaveValue('Draft to keep');
            expect(input).toHaveFocus();
        }
    });

    it('starts a new workspace when switching between DOI and IGSN portals', async () => {
        const view = render(
            <PortalLayout portalKind="doi">
                <input aria-label="Search draft" defaultValue="" />
                <p>DOI results</p>
            </PortalLayout>,
        );
        const input = screen.getByRole('textbox', { name: 'Search draft' });
        fireEvent.change(input, { target: { value: 'DOI draft' } });

        mockPage.url = '/igsn-search';
        view.rerender(
            <PortalLayout portalKind="igsn">
                <input aria-label="Search draft" defaultValue="" />
                <p>IGSN results</p>
            </PortalLayout>,
        );

        await waitFor(() => expect(screen.getByText('IGSN results')).toBeVisible());
        expect(screen.getByRole('textbox', { name: 'Search draft' })).not.toBe(input);
        expect(screen.getByRole('textbox', { name: 'Search draft' })).toHaveValue('');
    });

    describe('rendering', () => {
        it('renders children', () => {
            render(
                <PortalLayout>
                    <div data-testid="child-content">Hello Portal</div>
                </PortalLayout>,
            );
            expect(screen.getByTestId('child-content')).toBeInTheDocument();
            expect(screen.getByText('Hello Portal')).toBeInTheDocument();
        });

        it('renders portal header', () => {
            render(<PortalLayout><div /></PortalLayout>);
            expect(screen.getByTestId('portal-header')).toBeInTheDocument();
            expect(screen.getByText('GFZ Data Services Portal')).toBeInTheDocument();
        });

        it('uses a viewport-bound workspace without the global footer', () => {
            render(<PortalLayout><div /></PortalLayout>);
            expect(screen.queryByTestId('app-footer')).not.toBeInTheDocument();
            expect(screen.getByTestId('portal-header').parentElement?.parentElement).toHaveClass('h-dvh', 'overflow-hidden');
        });
    });

    describe('no auth links', () => {
        it('does not show dashboard link', () => {
            render(<PortalLayout><div /></PortalLayout>);
            expect(screen.queryByText('Dashboard')).not.toBeInTheDocument();
        });

        it('does not show login link', () => {
            render(<PortalLayout><div /></PortalLayout>);
            expect(screen.queryByText('Log in')).not.toBeInTheDocument();
        });
    });
});

import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { createMockUser } from '@test-helpers/types';

const usePageMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: unknown; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => {
        const resolvedHref = typeof href === 'string'
            ? href
            : href && typeof href === 'object' && 'url' in (href as Record<string, unknown>)
                ? String((href as { url: string }).url)
                : '';
        return <a href={resolvedHref} {...props}>{children}</a>;
    },
    usePage: () => usePageMock(),
}));

vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/dashboard' }),
}));

vi.mock('@/components/app-footer', () => ({
    AppFooter: () => <footer data-testid="app-footer">Footer</footer>,
}));

import PortalLayout from '@/layouts/portal-layout';

describe('PortalLayout', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({
            props: { auth: { user: createMockUser() } },
        });
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

        it('renders ERNIE brand link', () => {
            render(<PortalLayout><div /></PortalLayout>);
            const brandLink = screen.getByText('ERNIE');
            expect(brandLink).toBeInTheDocument();
            expect(brandLink.closest('a')).toHaveAttribute('href', '/');
        });

        it('renders footer', () => {
            render(<PortalLayout><div /></PortalLayout>);
            expect(screen.getByTestId('app-footer')).toBeInTheDocument();
        });
    });

    describe('auth-dependent rendering', () => {
        it('shows dashboard link for authenticated users', () => {
            render(<PortalLayout><div /></PortalLayout>);
            const dashboardLink = screen.getByText('Dashboard');
            expect(dashboardLink).toBeInTheDocument();
            expect(dashboardLink.closest('a')).toHaveAttribute('href', '/dashboard');
        });

        it('hides dashboard link for unauthenticated users', () => {
            usePageMock.mockReturnValue({
                props: { auth: { user: null } },
            });
            render(<PortalLayout><div /></PortalLayout>);
            expect(screen.queryByText('Dashboard')).not.toBeInTheDocument();
        });
    });
});

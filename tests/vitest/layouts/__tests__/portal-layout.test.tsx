import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import React from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: unknown; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => {
        const resolvedHref = typeof href === 'string'
            ? href
            : href && typeof href === 'object' && 'url' in (href as Record<string, unknown>)
                ? String((href as { url: string }).url)
                : '';
        return <a href={resolvedHref} {...props}>{children}</a>;
    },
}));

vi.mock('@/routes', () => ({}));

vi.mock('@/components/app-footer', () => ({
    AppFooter: () => <footer data-testid="app-footer">Footer</footer>,
}));

import PortalLayout from '@/layouts/portal-layout';

describe('PortalLayout', () => {
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

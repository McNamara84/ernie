import '@testing-library/jest-dom/vitest';

import { createMockUser } from '@test-helpers/types';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

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
    portal: () => ({ url: '/portal' }),
    changelog: () => ({ url: '/changelog' }),
}));

vi.mock('@/components/app-footer', () => ({
    AppFooter: () => <footer data-testid="app-footer">Footer</footer>,
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, className, asChild, ...props }: { children?: React.ReactNode; className?: string; asChild?: boolean } & React.ButtonHTMLAttributes<HTMLButtonElement>) => {
        if (asChild && React.isValidElement(children)) {
            return React.cloneElement(children as React.ReactElement<Record<string, unknown>>, { className, ...props });
        }
        return <button className={className} {...props}>{children}</button>;
    },
}));

vi.mock('@/lib/utils', () => ({
    cn: (...classes: (string | boolean | undefined)[]) => classes.filter(Boolean).join(' '),
}));

vi.mock('lucide-react', () => ({
    ArrowLeft: () => <svg data-testid="arrow-left-icon" />,
}));

import ChangelogLayout from '@/layouts/changelog-layout';

describe('ChangelogLayout', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({
            props: { auth: { user: createMockUser() } },
            url: '/changelog',
        });
    });

    describe('rendering', () => {
        it('renders children', () => {
            render(
                <ChangelogLayout>
                    <div data-testid="child-content">Hello Changelog</div>
                </ChangelogLayout>,
            );
            expect(screen.getByTestId('child-content')).toBeInTheDocument();
            expect(screen.getByText('Hello Changelog')).toBeInTheDocument();
        });

        it('renders footer', () => {
            render(<ChangelogLayout><div /></ChangelogLayout>);
            expect(screen.getByTestId('app-footer')).toBeInTheDocument();
        });

        it('renders navigation links for Portal and Changelog', () => {
            render(<ChangelogLayout><div /></ChangelogLayout>);
            const portalLink = screen.getByRole('link', { name: 'Portal' });
            const changelogLink = screen.getByRole('link', { name: 'Changelog' });
            expect(portalLink).toHaveAttribute('href', '/portal');
            expect(changelogLink).toHaveAttribute('href', '/changelog');
        });

        it('highlights the current page in navigation', () => {
            render(<ChangelogLayout><div /></ChangelogLayout>);
            const changelogLink = screen.getByRole('link', { name: 'Changelog' });
            expect(changelogLink).toHaveClass('bg-accent');
            expect(changelogLink).toHaveClass('font-medium');
        });

        it('does not highlight non-current pages', () => {
            render(<ChangelogLayout><div /></ChangelogLayout>);
            const portalLink = screen.getByRole('link', { name: 'Portal' });
            expect(portalLink).not.toHaveClass('bg-accent');
        });
    });

    describe('auth-dependent back button', () => {
        it('shows "Back to Dashboard" for authenticated users', () => {
            render(<ChangelogLayout><div /></ChangelogLayout>);
            const backLink = screen.getByRole('link', { name: /back to dashboard/i });
            expect(backLink).toBeInTheDocument();
            expect(backLink).toHaveAttribute('href', '/dashboard');
        });

        it('shows "Portal" for unauthenticated users', () => {
            usePageMock.mockReturnValue({
                props: { auth: { user: null } },
                url: '/changelog',
            });
            render(<ChangelogLayout><div /></ChangelogLayout>);
            const portalLinks = screen.getAllByRole('link', { name: /portal/i });
            // Back button + nav link = 2 portal links
            expect(portalLinks).toHaveLength(2);
            expect(portalLinks[0]).toHaveAttribute('href', '/portal');
            expect(screen.queryByText(/back to dashboard/i)).not.toBeInTheDocument();
        });

        it('renders back arrow icon', () => {
            render(<ChangelogLayout><div /></ChangelogLayout>);
            expect(screen.getByTestId('arrow-left-icon')).toBeInTheDocument();
        });
    });
});

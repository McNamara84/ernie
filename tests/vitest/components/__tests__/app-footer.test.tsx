import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppFooter from '@/components/app-footer';
import { latestVersion } from '@/lib/version';

const usePageMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: unknown; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => {
        const resolvedHref =
            typeof href === 'string'
                ? href
                : href && typeof href === 'object' && 'url' in (href as Record<string, unknown>)
                  ? String((href as { url: string }).url)
                  : '';

        return (
            <a href={resolvedHref} {...props}>
                {children}
            </a>
        );
    },
    usePage: () => usePageMock(),
}));

vi.mock('@/routes', () => {
    const makeRoute = (path: string) => ({ url: path });

    return {
        about: () => makeRoute('/about'),
        legalNotice: () => makeRoute('/legal-notice'),
        changelog: () => makeRoute('/changelog'),
    };
});

describe('AppFooter', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({ props: { auth: { user: null } } });
    });

    it('shows the version as plain text for guests', () => {
        render(<AppFooter />);

        expect(screen.getByText(`ERNIE v${latestVersion}`)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /view changelog/i })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: /about/i })).toHaveAttribute('href', '/about');
        expect(screen.getByRole('link', { name: /legal notice/i })).toHaveAttribute('href', '/legal-notice');
    });

    it('shows the version as plain text for unverified users', () => {
        usePageMock.mockReturnValue({ props: { auth: { user: { email_verified_at: null } } } });

        render(<AppFooter />);

        expect(screen.getByText(`ERNIE v${latestVersion}`)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /view changelog/i })).not.toBeInTheDocument();
    });

    it('links the version to the changelog for verified users', () => {
        usePageMock.mockReturnValue({ props: { auth: { user: { email_verified_at: '2026-09-25T00:00:00Z' } } } });

        render(<AppFooter />);

        const versionLink = screen.getByRole('link', {
            name: new RegExp(`view changelog for version ${latestVersion}`, 'i'),
        });
        expect(versionLink).toHaveTextContent(`ERNIE v${latestVersion}`);
        expect(versionLink).toHaveAttribute('href', '/changelog');
    });
});

import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import AppFooter from '@/components/app-footer';
import { latestVersion } from '@/lib/version';

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
}));

vi.mock('@/routes', async () => {
    const makeRoute = (path: string) => ({ url: path });

    return {
        about: () => makeRoute('/about'),
        legalNotice: () => makeRoute('/legal-notice'),
        changelog: () => makeRoute('/changelog'),
    };
});

afterEach(() => {
    document.head.innerHTML = '';
});

describe('AppFooter', () => {
    it('displays version and navigation links', () => {
        render(<AppFooter />);
        const versionLink = screen.getByRole('link', {
            name: new RegExp(`view changelog for version ${latestVersion}`, 'i'),
        });
        expect(versionLink).toHaveTextContent(`ERNIE v${latestVersion}`);
        expect(versionLink).toHaveAttribute('href', '/changelog');
        expect(screen.getByRole('link', { name: /about/i })).toHaveAttribute('href', '/about');
        expect(screen.getByRole('link', { name: /legal notice/i })).toHaveAttribute('href', '/legal-notice');
    });

});


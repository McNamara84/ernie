import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import AppFooter from '@/components/app-footer';
import { latestVersion } from '@/lib/version';
import { __testing as basePathTesting } from '@/lib/base-path';
import { afterEach, describe, expect, it, vi } from 'vitest';

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
    const { withBasePath } = await import('@/lib/base-path');

    const makeRoute = (path: string) => ({ url: withBasePath(path) });

    return {
        about: () => makeRoute('/about'),
        legalNotice: () => makeRoute('/legal-notice'),
        changelog: () => makeRoute('/changelog'),
    };
});

afterEach(() => {
    document.head.innerHTML = '';
    basePathTesting.resetBasePathCache();
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

    it('prefixes links with the configured base path', () => {
        basePathTesting.setMetaBasePath('/ernie');
        render(<AppFooter />);
        const versionLink = screen.getByRole('link', {
            name: new RegExp(`view changelog for version ${latestVersion}`, 'i'),
        });
        expect(versionLink).toHaveAttribute('href', '/ernie/changelog');
        expect(screen.getByRole('link', { name: /about/i })).toHaveAttribute('href', '/ernie/about');
        expect(screen.getByRole('link', { name: /legal notice/i })).toHaveAttribute('href', '/ernie/legal-notice');
    });
});


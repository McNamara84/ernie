import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import AppFooter from '../app-footer';
import { latestVersion } from '@/lib/version';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children?: React.ReactNode } & React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

vi.mock('@/routes', () => ({
    about: () => '/about',
    legalNotice: () => '/legal-notice',
}));

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


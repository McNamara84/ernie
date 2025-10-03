import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AuthSimpleLayout from '@/layouts/auth/auth-simple-layout';

function resolveHref(href: unknown): string {
    if (typeof href === 'string') {
        return href;
    }

    if (href && typeof href === 'object' && 'url' in href && typeof (href as { url?: unknown }).url === 'string') {
        return (href as { url: string }).url;
    }

    return '';
}

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: unknown; children?: React.ReactNode }) => (
        <a href={resolveHref(href)}>{children}</a>
    ),
}));

function createRoute(path: string) {
    const routeFn = () => ({ url: path });
    routeFn.url = () => path;
    return routeFn;
}

vi.mock('@/routes', () => ({
    home: createRoute('/'),
    about: createRoute('/about'),
    legalNotice: createRoute('/legal-notice'),
    changelog: createRoute('/changelog'),
}));

describe('AuthSimpleLayout', () => {
    it('renders link to home with title, description and children', () => {
        render(
            <AuthSimpleLayout title="Sign in" description="Welcome">
                <p>Child content</p>
            </AuthSimpleLayout>,
        );

        expect(screen.getByRole('link', { name: /sign in/i })).toHaveAttribute('href', '/');
        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.getByText('Welcome')).toBeInTheDocument();
        expect(screen.getByText('Child content')).toBeInTheDocument();
    });
});


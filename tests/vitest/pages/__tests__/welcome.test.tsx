import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Welcome from '@/pages/welcome';

// mock @inertiajs/react and routes used in the component
const usePageMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ children, href }: { children?: React.ReactNode; href: unknown }) => {
        const resolvedHref =
            typeof href === 'string'
                ? href
                : href && typeof href === 'object' && 'url' in (href as Record<string, unknown>)
                  ? String((href as { url: string }).url)
                  : '';

        return <a href={resolvedHref}>{children}</a>;
    },
    usePage: () => usePageMock(),
}));

vi.mock('@/routes', () => {
    const makeRoute = (path: string) => ({ url: path });

    return {
        dashboard: () => makeRoute('/dashboard'),
        login: () => makeRoute('/login'),
        about: () => makeRoute('/about'),
        legalNotice: () => makeRoute('/legal-notice'),
        changelog: () => makeRoute('/changelog'),
    };
});

describe('Welcome', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({ props: { auth: {} } });
    });

    it('renders the heading', () => {
        render(<Welcome />);
        expect(
            screen.getByRole('heading', {
                name: /ERNIE - Earth Research Notary for Information Editing/i,
            }),
        ).toBeInTheDocument();
    });

    it('shows login link when user is unauthenticated', () => {
        render(<Welcome />);
        expect(screen.getByRole('link', { name: /log in/i })).toHaveAttribute('href', '/login');
    });

    it('shows dashboard link when user is authenticated', () => {
        usePageMock.mockReturnValue({ props: { auth: { user: { id: 1 } } } });
        render(<Welcome />);
        expect(screen.getByRole('link', { name: /dashboard/i })).toHaveAttribute('href', '/dashboard');
    });

    it('includes link to API documentation', () => {
        render(<Welcome />);
        expect(screen.getByRole('link', { name: /api documentation/i })).toHaveAttribute(
            'href',
            '/api/v1/doc'
        );
    });
});

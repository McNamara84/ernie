import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import PublicLayout from '@/layouts/public-layout';

// Mock Inertia's usePage hook
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();
    return {
        ...actual,
        usePage: vi.fn(() => ({
            props: {
                auth: {
                    user: null,
                },
            },
        })),
        Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
            <a href={href} {...props}>
                {children}
            </a>
        ),
    };
});

// Mock routes
vi.mock('@/routes', () => ({
    dashboard: () => '/dashboard',
    login: () => '/login',
}));

// Mock AppFooter
vi.mock('@/components/app-footer', () => ({
    AppFooter: () => <footer data-testid="app-footer">Footer</footer>,
}));

describe('PublicLayout', () => {
    it('renders children content', () => {
        render(
            <PublicLayout>
                <div data-testid="child-content">Test Content</div>
            </PublicLayout>
        );

        expect(screen.getByTestId('child-content')).toBeInTheDocument();
        expect(screen.getByText('Test Content')).toBeInTheDocument();
    });

    it('renders the app footer', () => {
        render(
            <PublicLayout>
                <div>Content</div>
            </PublicLayout>
        );

        expect(screen.getByTestId('app-footer')).toBeInTheDocument();
    });

    it('shows login link when user is not authenticated', async () => {
        const { usePage } = await import('@inertiajs/react');
        vi.mocked(usePage).mockReturnValue({
            props: {
                auth: {
                    user: null,
                },
            },
        } as unknown as ReturnType<typeof usePage>);

        render(
            <PublicLayout>
                <div>Content</div>
            </PublicLayout>
        );

        const loginLink = screen.getByRole('link', { name: /log in/i });
        expect(loginLink).toBeInTheDocument();
        expect(loginLink).toHaveAttribute('href', '/login');
    });

    it('shows dashboard link when user is authenticated', async () => {
        const { usePage } = await import('@inertiajs/react');
        vi.mocked(usePage).mockReturnValue({
            props: {
                auth: {
                    user: { id: 1, name: 'Test User', email: 'test@example.com' },
                },
            },
        } as unknown as ReturnType<typeof usePage>);

        render(
            <PublicLayout>
                <div>Content</div>
            </PublicLayout>
        );

        const dashboardLink = screen.getByRole('link', { name: /dashboard/i });
        expect(dashboardLink).toBeInTheDocument();
        expect(dashboardLink).toHaveAttribute('href', '/dashboard');
    });

    it('has correct layout structure with header and main', () => {
        render(
            <PublicLayout>
                <div>Content</div>
            </PublicLayout>
        );

        expect(screen.getByRole('banner')).toBeInTheDocument(); // header
        expect(screen.getByRole('main')).toBeInTheDocument();
    });
});

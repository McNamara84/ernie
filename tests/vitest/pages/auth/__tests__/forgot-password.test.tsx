import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ForgotPassword from '@/pages/auth/forgot-password';

const { routerMock } = vi.hoisted(() => ({
    routerMock: {
        post: vi.fn(),
    },
}));

function resolveHref(href: unknown): string {
    if (typeof href === 'string') return href;
    if (href && typeof href === 'object' && 'url' in href && typeof (href as { url?: unknown }).url === 'string') {
        return (href as { url: string }).url;
    }
    return '';
}

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ children, href }: { children?: React.ReactNode; href: unknown }) => <a href={resolveHref(href)}>{children}</a>,
    router: routerMock,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/PasswordResetLinkController', () => ({
    default: { store: { url: () => '/forgot-password' } },
}));

function createRoute(path: string) {
    const routeFn = () => ({ url: path });
    routeFn.url = () => path;
    return routeFn;
}

vi.mock('@/routes', () => ({
    login: createRoute('/login'),
    home: createRoute('/'),
    about: createRoute('/about'),
    legalNotice: createRoute('/legal-notice'),
    changelog: createRoute('/changelog'),
}));

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/text-link', () => ({
    default: ({ href, children }: { href: string | { url: string }; children?: React.ReactNode }) => (
        <a href={typeof href === 'string' ? href : href?.url}>{children}</a>
    ),
}));

describe('ForgotPassword page', () => {
    beforeEach(() => {
        routerMock.post.mockReset();
    });

    it('renders email field and submit button', () => {
        render(<ForgotPassword />);
        expect(screen.getByLabelText(/email address/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /email password reset link/i })).toBeInTheDocument();
    });

    it('shows status message when provided', () => {
        render(<ForgotPassword status="Link sent" />);
        expect(screen.getByText(/link sent/i)).toBeInTheDocument();
    });

    it('displays validation error on submit with empty email', async () => {
        render(<ForgotPassword />);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /email password reset link/i }));

        await waitFor(() => {
            expect(screen.getByText(/please enter a valid email address/i)).toBeInTheDocument();
        });
    });

    it('disables submit button when processing', async () => {
        routerMock.post.mockImplementation(() => {});
        render(<ForgotPassword />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/email address/i), 'user@example.com');
        await user.click(screen.getByRole('button', { name: /email password reset link/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /email password reset link/i })).toBeDisabled();
        });
    });

    it('links back to login page', () => {
        render(<ForgotPassword />);
        expect(screen.getByRole('link', { name: /log in/i })).toHaveAttribute('href', '/login');
    });
});

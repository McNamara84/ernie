import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ForgotPassword from '@/pages/auth/forgot-password';

let formErrors: { email?: string };
let formProcessing: boolean;

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
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Form: ({ children }: { children: (args: { processing: boolean; errors: typeof formErrors }) => React.ReactNode }) => (
        <form>{children({ processing: formProcessing, errors: formErrors })}</form>
    ),
    Link: ({ children, href }: { children?: React.ReactNode; href: unknown }) => (
        <a href={resolveHref(href)}>{children}</a>
    ),
}));

vi.mock('@/actions/App/Http/Controllers/Auth/PasswordResetLinkController', () => ({
    default: {
        store: {
            form: () => ({}),
        },
    },
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

describe('ForgotPassword page', () => {
    beforeEach(() => {
        formErrors = {};
        formProcessing = false;
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

    it('displays validation error', () => {
        formErrors.email = 'Email is required';
        render(<ForgotPassword />);
        expect(screen.getByText(/email is required/i)).toBeInTheDocument();
    });

    it('disables submit button when processing', () => {
        formProcessing = true;
        render(<ForgotPassword />);
        expect(screen.getByRole('button', { name: /email password reset link/i })).toBeDisabled();
    });

    it('links back to login page', () => {
        render(<ForgotPassword />);
        expect(screen.getByRole('link', { name: /log in/i })).toHaveAttribute('href', '/login');
    });
});


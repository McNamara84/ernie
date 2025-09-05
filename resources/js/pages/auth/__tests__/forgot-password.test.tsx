import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import ForgotPassword from '../forgot-password';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const formErrors: { email?: string } = {};
let formProcessing = false;

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Form: ({ children }: { children: (args: { processing: boolean; errors: typeof formErrors }) => React.ReactNode }) => (
        <form>{children({ processing: formProcessing, errors: formErrors })}</form>
    ),
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/PasswordResetLinkController', () => ({
    default: {
        store: {
            form: () => ({}),
        },
    },
}));

vi.mock('@/routes', () => ({
    login: () => '/login',
    home: () => '/',
}));

describe('ForgotPassword page', () => {
    beforeEach(() => {
        formErrors.email = undefined;
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


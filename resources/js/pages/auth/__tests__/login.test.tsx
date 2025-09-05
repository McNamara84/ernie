import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import Login from '../login';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const formErrors: { email?: string; password?: string } = {};
let formProcessing = false;

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Form: ({
        children,
    }: {
        children: (args: { processing: boolean; errors: typeof formErrors }) => React.ReactNode;
    }) => <form>{children({ processing: formProcessing, errors: formErrors })}</form>,
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController', () => ({
    default: {
        store: {
            form: () => ({}),
        },
    },
}));

vi.mock('@/routes/password', () => ({
    request: () => '/forgot-password',
}));

vi.mock('@/routes', () => ({
    home: () => '/',
}));

describe('Login', () => {
    beforeEach(() => {
        formErrors.email = undefined;
        formErrors.password = undefined;
        formProcessing = false;
    });

    it('renders form fields and submit button', () => {
        render(<Login canResetPassword={true} />);
        expect(screen.getByLabelText(/email address/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /log in/i })).toBeInTheDocument();
    });

    it('shows forgot password link when allowed', () => {
        render(<Login canResetPassword={true} />);
        expect(screen.getByRole('link', { name: /forgot password/i })).toHaveAttribute(
            'href',
            '/forgot-password',
        );
    });

    it('hides forgot password link when not allowed', () => {
        render(<Login canResetPassword={false} />);
        expect(screen.queryByRole('link', { name: /forgot password/i })).toBeNull();
    });

    it('displays status message', () => {
        render(<Login status="Session expired" canResetPassword={true} />);
        expect(screen.getByText(/session expired/i)).toBeInTheDocument();
    });

    it('shows validation errors', () => {
        formErrors.email = 'Email is required';
        formErrors.password = 'Password is required';
        render(<Login canResetPassword={true} />);
        expect(screen.getByText(/email is required/i)).toBeInTheDocument();
        expect(screen.getByText(/password is required/i)).toBeInTheDocument();
    });

    it('disables submit button when processing', () => {
        formProcessing = true;
        render(<Login canResetPassword={true} />);
        expect(screen.getByRole('button', { name: /log in/i })).toBeDisabled();
    });
});


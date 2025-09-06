import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import ResetPassword from '../reset-password';
import { beforeEach, describe, expect, it, vi } from 'vitest';

let formErrors: {
    email?: string;
    password?: string;
    password_confirmation?: string;
};
let formProcessing: boolean;

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Form: ({ children }: { children: (args: { processing: boolean; errors: typeof formErrors }) => React.ReactNode }) => (
        <form>{children({ processing: formProcessing, errors: formErrors })}</form>
    ),
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/NewPasswordController', () => ({
    default: {
        store: {
            form: () => ({}),
        },
    },
}));

describe('ResetPassword page', () => {
    beforeEach(() => {
        formErrors = {};
        formProcessing = false;
    });

    const token = 'token123';
    const email = 'user@example.com';

    it('renders fields and submit button with preset email', () => {
        render(<ResetPassword token={token} email={email} />);
        expect(screen.getByLabelText(/email/i)).toHaveValue(email);
        expect(screen.getByLabelText(/^password$/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/confirm password/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /reset password/i })).toBeInTheDocument();
    });

    it('displays validation errors', () => {
        formErrors = {
            email: 'Email is required',
            password: 'Password is required',
            password_confirmation: 'Confirmation is required',
        };
        render(<ResetPassword token={token} email={email} />);
        expect(screen.getByText(/email is required/i)).toBeInTheDocument();
        expect(screen.getByText(/password is required/i)).toBeInTheDocument();
        expect(screen.getByText(/confirmation is required/i)).toBeInTheDocument();
    });

    it('disables submit button when processing', () => {
        formProcessing = true;
        render(<ResetPassword token={token} email={email} />);
        expect(screen.getByRole('button', { name: /reset password/i })).toBeDisabled();
    });
});

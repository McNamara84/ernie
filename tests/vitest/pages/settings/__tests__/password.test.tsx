import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Password from '@/pages/settings/password';

let formErrors: Record<string, string> = {};
let processing = false;
let recentlySuccessful = false;

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Form: ({ children }: { children: (args: { errors: typeof formErrors; processing: boolean; recentlySuccessful: boolean }) => React.ReactNode }) => (
        <form>{children({ errors: formErrors, processing, recentlySuccessful })}</form>
    ),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/routes/password', () => ({
    edit: () => ({ url: '/settings/password' }),
}));

vi.mock('@/actions/App/Http/Controllers/Settings/PasswordController', () => ({
    default: { update: { form: () => ({}) } },
}));

describe('Password settings page', () => {
    beforeEach(() => {
        formErrors = {};
        processing = false;
        recentlySuccessful = false;
    });

    it('renders fields for updating password', () => {
        render(<Password />);
        expect(screen.getByLabelText(/current password/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/^new password$/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/confirm password/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /save password/i })).toBeInTheDocument();
    });

    it('shows validation errors', () => {
        formErrors = {
            current_password: 'Current password is required',
            password: 'Password is required',
            password_confirmation: 'Confirmation is required',
        };
        render(<Password />);
        expect(screen.getByText(/current password is required/i)).toBeInTheDocument();
        expect(screen.getByText(/^password is required$/i)).toBeInTheDocument();
        expect(screen.getByText(/confirmation is required/i)).toBeInTheDocument();
    });

    it('disables button when processing and shows success message', () => {
        processing = true;
        recentlySuccessful = true;
        render(<Password />);
        expect(screen.getByRole('button', { name: /save password/i })).toBeDisabled();
        expect(screen.getByText(/saved/i)).toBeInTheDocument();
    });
});


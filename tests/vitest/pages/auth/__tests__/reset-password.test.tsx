import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ResetPassword from '@/pages/auth/reset-password';

type FormErrors = {
    email?: string;
    password?: string;
    password_confirmation?: string;
};

type FormState = {
    errors: FormErrors;
    processing: boolean;
};

function createInertiaMock({ errors, processing }: FormState) {
    return {
        Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
        Form: ({
            children,
        }: {
            children: (args: { processing: boolean; errors: FormErrors }) => React.ReactNode;
        }) => <form>{children({ processing, errors })}</form>,
        Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    };
}

const inertiaMock = vi.hoisted(() => createInertiaMock({ errors: {}, processing: false }));

vi.mock('@inertiajs/react', () => inertiaMock);

vi.mock('@/actions/App/Http/Controllers/Auth/NewPasswordController', () => ({
    default: {
        store: {
            post: () => ({}),
        },
    },
}));

describe('ResetPassword page', () => {
    const token = 'token123';
    const email = 'user@example.com';

    const setup = (state: Partial<FormState> = {}) => {
        Object.assign(inertiaMock, createInertiaMock({ errors: {}, processing: false, ...state }));
        render(<ResetPassword token={token} email={email} />);
    };

    it('renders fields and submit button with preset email', () => {
        setup();
        expect(screen.getByLabelText(/email/i)).toHaveValue(email);
        expect(screen.getByLabelText('Password')).toBeInTheDocument();
        expect(screen.getByLabelText(/confirm password/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /reset password/i })).toBeInTheDocument();
    });

    it('displays validation errors', () => {
        setup({
            errors: {
                email: 'Email is required',
                password: 'Password is required',
                password_confirmation: 'Confirmation is required',
            },
        });
        expect(screen.getByText(/email is required/i)).toBeInTheDocument();
        expect(screen.getByText(/password is required/i)).toBeInTheDocument();
        expect(screen.getByText(/confirmation is required/i)).toBeInTheDocument();
    });

    it('disables submit button when processing', () => {
        setup({ processing: true });
        expect(screen.getByRole('button', { name: /reset password/i })).toBeDisabled();
    });
});

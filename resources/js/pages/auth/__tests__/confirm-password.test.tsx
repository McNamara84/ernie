import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import ConfirmPassword from '../confirm-password';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const formErrors: { password?: string } = {};
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

vi.mock('@/routes', () => ({
    home: () => '/',
    about: () => '/about',
    legalNotice: () => '/legal-notice',
}));

vi.mock('@/actions/App/Http/Controllers/Auth/ConfirmablePasswordController', () => ({
    default: {
        store: {
            form: () => ({}),
        },
    },
}));

describe('ConfirmPassword page', () => {
    beforeEach(() => {
        formErrors.password = undefined;
        formProcessing = false;
    });

    it('renders password field and submit button', () => {
        render(<ConfirmPassword />);
        expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /confirm password/i })).toBeInTheDocument();
    });

    it('shows validation error', () => {
        formErrors.password = 'Required';
        render(<ConfirmPassword />);
        expect(screen.getByText(/required/i)).toBeInTheDocument();
    });

    it('disables submit button when processing', () => {
        formProcessing = true;
        render(<ConfirmPassword />);
        expect(screen.getByRole('button', { name: /confirm password/i })).toBeDisabled();
    });
});


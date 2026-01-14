import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Welcome from '@/pages/auth/welcome';

// Mock dependencies
vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Form: ({
        children,
    }: {
        children: (props: { processing: boolean; errors: Record<string, string> }) => React.ReactNode;
    }) => <form data-testid="welcome-form">{children({ processing: false, errors: {} })}</form>,
}));

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children, title, description }: { children: React.ReactNode; title: string; description: string }) => (
        <div data-testid="auth-layout" data-title={title} data-description={description}>
            {children}
        </div>
    ),
}));

vi.mock('@/actions/App/Http/Controllers/Auth/WelcomeController', () => ({
    default: {
        store: {
            form: vi.fn(() => ({ method: 'post', action: '/welcome' })),
        },
    },
}));

describe('Welcome', () => {
    const defaultProps = {
        email: 'user@example.com',
        userId: 123,
    };

    it('renders the welcome page', () => {
        render(<Welcome {...defaultProps} />);

        expect(screen.getByTestId('auth-layout')).toBeInTheDocument();
    });

    it('displays the welcome title', () => {
        render(<Welcome {...defaultProps} />);

        const layout = screen.getByTestId('auth-layout');
        expect(layout).toHaveAttribute('data-title', 'Welcome to ERNIE');
    });

    it('displays the description', () => {
        render(<Welcome {...defaultProps} />);

        const layout = screen.getByTestId('auth-layout');
        expect(layout).toHaveAttribute('data-description', 'Set your password to activate your account');
    });

    it('renders email input as readonly', () => {
        render(<Welcome {...defaultProps} />);

        const emailInput = screen.getByLabelText('Email') as HTMLInputElement;
        expect(emailInput).toBeInTheDocument();
        expect(emailInput).toHaveAttribute('readonly');
        expect(emailInput.value).toBe('user@example.com');
    });

    it('renders password input field', () => {
        render(<Welcome {...defaultProps} />);

        expect(screen.getByLabelText('Password')).toBeInTheDocument();
    });

    it('renders password confirmation input field', () => {
        render(<Welcome {...defaultProps} />);

        expect(screen.getByLabelText('Confirm Password')).toBeInTheDocument();
    });

    it('renders submit button', () => {
        render(<Welcome {...defaultProps} />);

        expect(screen.getByRole('button', { name: /Set Password & Continue/i })).toBeInTheDocument();
    });

    it('has password placeholders', () => {
        render(<Welcome {...defaultProps} />);

        expect(screen.getByPlaceholderText('Enter your new password')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Confirm your password')).toBeInTheDocument();
    });

    it('renders the form element', () => {
        render(<Welcome {...defaultProps} />);

        expect(screen.getByTestId('welcome-form')).toBeInTheDocument();
    });
});

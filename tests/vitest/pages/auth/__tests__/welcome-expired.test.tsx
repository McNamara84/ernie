import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia
vi.mock('@inertiajs/react', () => ({
    Form: ({ children, ...props }: { children: (args: { processing: boolean }) => React.ReactNode }) =>
        typeof children === 'function' ? (
            <form data-testid="inertia-form" {...props}>
                {children({ processing: false })}
            </form>
        ) : (
            <form data-testid="inertia-form" {...props}>
                {children}
            </form>
        ),
    Head: ({ title }: { title: string }) => <title>{title}</title>,
}));

// Mock WelcomeController
vi.mock('@/actions/App/Http/Controllers/Auth/WelcomeController', () => ({
    default: {
        resend: {
            post: () => ({ action: '/welcome/resend', method: 'post' }),
        },
    },
}));

// Mock AuthLayout
vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children, title, description }: { children: React.ReactNode; title: string; description: string }) => (
        <div data-testid="auth-layout">
            <h1>{title}</h1>
            <p>{description}</p>
            {children}
        </div>
    ),
}));

import WelcomeExpired from '@/pages/auth/welcome-expired';

describe('WelcomeExpired', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the page title', () => {
        render(<WelcomeExpired email="test@example.com" />);

        expect(screen.getByRole('heading', { name: 'Link Expired' })).toBeInTheDocument();
    });

    it('renders the expired link alert', () => {
        render(<WelcomeExpired email="test@example.com" />);

        // Use getAllByText since texts appear in both mock layout and actual alert
        const linkExpiredTexts = screen.getAllByText('Link Expired');
        expect(linkExpiredTexts.length).toBeGreaterThanOrEqual(1);
        const expiredTexts = screen.getAllByText(/Your welcome link has expired/);
        expect(expiredTexts.length).toBeGreaterThanOrEqual(1);
    });

    it('renders the email input with prefilled value', () => {
        render(<WelcomeExpired email="test@example.com" />);

        const emailInput = screen.getByLabelText('Email');
        expect(emailInput).toBeInTheDocument();
        expect(emailInput).toHaveValue('test@example.com');
    });

    it('makes email input readonly when email is provided', () => {
        render(<WelcomeExpired email="test@example.com" />);

        const emailInput = screen.getByLabelText('Email');
        expect(emailInput).toHaveAttribute('readonly');
    });

    it('makes email input editable when email is empty', () => {
        render(<WelcomeExpired email="" />);

        const emailInput = screen.getByLabelText('Email');
        expect(emailInput).not.toHaveAttribute('readonly');
    });

    it('renders the submit button', () => {
        render(<WelcomeExpired email="test@example.com" />);

        expect(screen.getByRole('button', { name: /Send New Welcome Email/i })).toBeInTheDocument();
    });

    it('shows email icon in submit button', () => {
        render(<WelcomeExpired email="test@example.com" />);

        const button = screen.getByRole('button', { name: /Send New Welcome Email/i });
        expect(button.querySelector('svg')).toBeInTheDocument();
    });
});

import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import Login from '../login';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Form: ({ children }: { children?: ReactNode | ((args: { processing: boolean; errors: Record<string, string> }) => ReactNode) }) => (
        <form>
            {typeof children === 'function'
                ? children({ processing: true, errors: { email: 'Invalid email', password: 'Required' } })
                : children}
        </form>
    ),
    Head: ({ children }: { children?: ReactNode }) => <>{children}</>,
    Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController', () => ({
    default: { store: { form: () => ({}) } },
}));

vi.mock('@/routes/password', () => ({
    request: () => '/forgot-password',
}));

vi.mock('@/components/text-link', () => ({
    default: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/components/input-error', () => ({
    default: ({ message }: { message?: string }) => (message ? <p>{message}</p> : null),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...props }: ComponentProps<'button'>) => <button {...props}>{children}</button>,
}));

vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: (props: ComponentProps<'input'>) => <input type="checkbox" {...props} />,
}));

vi.mock('@/components/ui/input', () => ({
    Input: (props: ComponentProps<'input'>) => <input {...props} />,
}));

vi.mock('@/components/ui/label', () => ({
    Label: ({ children, ...props }: ComponentProps<'label'>) => <label {...props}>{children}</label>,
}));

describe('Login page', () => {
    it('renders forgot password link when allowed', () => {
        render(<Login canResetPassword={true} />);
        const link = screen.getByRole('link', { name: /forgot password/i });
        expect(link).toHaveAttribute('href', '/forgot-password');
    });

    it('displays status message when provided', () => {
        render(<Login canResetPassword={false} status="Password reset" />);
        expect(screen.getByText('Password reset')).toBeInTheDocument();
    });

    it('renders validation errors', () => {
        render(<Login canResetPassword={false} />);
        expect(screen.getByText('Invalid email')).toBeInTheDocument();
        expect(screen.getByText('Required')).toBeInTheDocument();
    });

    it('disables the submit button and shows a spinner while processing', () => {
        render(<Login canResetPassword={false} />);
        const button = screen.getByRole('button', { name: /log in/i });
        expect(button).toBeDisabled();
        expect(button.querySelector('.animate-spin')).toBeInTheDocument();
    });
});

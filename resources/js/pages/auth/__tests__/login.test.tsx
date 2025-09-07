import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import Login from '../login';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Form: ({ children }: any) => <form>{typeof children === 'function' ? children({ processing: false, errors: {} }) : children}</form>,
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ href, children }: { href: string; children?: React.ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController', () => ({
    default: { store: { form: () => ({}) } },
}));

vi.mock('@/routes/password', () => ({
    request: () => '/forgot-password',
}));

vi.mock('@/components/text-link', () => ({
    default: ({ href, children }: { href: string; children?: React.ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/components/input-error', () => ({
    default: ({ message }: { message?: string }) => (message ? <p>{message}</p> : null),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, ...props }: any) => <button {...props}>{children}</button>,
}));

vi.mock('@/components/ui/checkbox', () => ({
    Checkbox: (props: any) => <input type="checkbox" {...props} />,
}));

vi.mock('@/components/ui/input', () => ({
    Input: (props: any) => <input {...props} />,
}));

vi.mock('@/components/ui/label', () => ({
    Label: ({ children, ...props }: any) => <label {...props}>{children}</label>,
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
});

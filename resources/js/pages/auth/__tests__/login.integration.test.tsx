import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import Login from '../login';
import { afterEach, describe, expect, it, vi } from 'vitest';

const originalLocation = window.location;

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

vi.mock('@inertiajs/react', () => {
    const React = require('react');
    const { useState } = React;
    return {
        Form: ({
            children,
        }: {
            children: (args: { processing: boolean; errors: Record<string, string> }) => ReactNode;
        }) => {
            const [errors, setErrors] = useState<Record<string, string>>({});
            const [processing, setProcessing] = useState(false);
            const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
                e.preventDefault();
                setProcessing(true);
                const data = Object.fromEntries(new FormData(e.currentTarget).entries());
                const response = await fetch('/login', {
                    method: 'post',
                    body: JSON.stringify(data),
                });
                setProcessing(false);
                if (response.ok) {
                    const json = await response.json();
                    window.location.assign(json.redirect);
                } else {
                    const json = await response.json();
                    setErrors(json.errors);
                }
            };
            return <form onSubmit={handleSubmit}>{children({ processing, errors })}</form>;
        },
        Head: ({ children }: { children?: ReactNode }) => <>{children}</>,
        Link: ({ href, children }: { href: string; children?: ReactNode }) => (
            <a href={href}>{children}</a>
        ),
    };
});

afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation });
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('Login integration', () => {
    it('redirects to dashboard on successful login', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            json: async () => ({ redirect: '/dashboard' }),
        })));
        const assignSpy = vi.fn();
        Object.defineProperty(window, 'location', {
            value: { assign: assignSpy },
        });
        render(<Login canResetPassword={false} />);
        fireEvent.input(screen.getByLabelText(/email address/i), {
            target: { value: 'user@example.com' },
        });
        fireEvent.input(screen.getByLabelText(/password/i), {
            target: { value: 'password' },
        });
        fireEvent.submit(screen.getByRole('button', { name: /log in/i }).closest('form')!);
        await waitFor(() => expect(assignSpy).toHaveBeenCalledWith('/dashboard'));
    });

    it('shows error message when credentials are invalid', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: false,
            json: async () => ({ errors: { email: 'Invalid credentials' } }),
        })));
        render(<Login canResetPassword={false} />);
        fireEvent.input(screen.getByLabelText(/email address/i), {
            target: { value: 'wrong@example.com' },
        });
        fireEvent.input(screen.getByLabelText(/password/i), {
            target: { value: 'bad' },
        });
        fireEvent.submit(screen.getByRole('button', { name: /log in/i }).closest('form')!);
        expect(await screen.findByText('Invalid credentials')).toBeInTheDocument();
    });
});

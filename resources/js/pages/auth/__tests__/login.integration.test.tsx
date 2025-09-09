import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState, type ComponentProps, type ReactNode } from 'react';
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
    return {
        Form: ({
            children,
        }: {
            children?: ReactNode | ((args: { processing: boolean; errors: Record<string, string> }) => ReactNode);
        }) => {
            const [errors, setErrors] = useState<Record<string, string>>({});
            const [processing, setProcessing] = useState(false);
            const isSafeRedirect = (url: string) => {
                try {
                    const parsed = new URL(url, window.location.origin);
                    return (
                        parsed.origin === window.location.origin &&
                        url.startsWith('/') &&
                        !url.startsWith('//')
                    );
                } catch {
                    return false;
                }
            };
            const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
                e.preventDefault();
                setProcessing(true);
                const data = new FormData(e.currentTarget);
                const response = await fetch('/login', {
                    method: 'POST',
                    body: data,
                });
                setProcessing(false);
                if (response.ok) {
                    const json = await response.json();
                    const redirect =
                        typeof json.redirect === 'string' && isSafeRedirect(json.redirect)
                            ? json.redirect
                            : '/';
                    window.location.assign(redirect);
                } else {
                    const json = await response.json();
                    setErrors(json.errors ?? {});
                }
            };
            return (
                <form onSubmit={handleSubmit}>
                    {typeof children === 'function'
                        ? children({ processing, errors })
                        : children}
                </form>
            );
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
            value: { assign: assignSpy, origin: 'http://localhost' },
        });
        render(<Login canResetPassword={false} />);
        fireEvent.input(screen.getByLabelText(/email address/i), {
            target: { value: 'user@example.com' },
        });
        fireEvent.input(screen.getByLabelText(/password/i), {
            target: { value: 'password' },
        });
        const button = screen.getByRole('button', { name: /log in/i });
        const form = button.closest('form');
        if (!form) throw new Error('Form not found');
        fireEvent.submit(form);
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
        const button = screen.getByRole('button', { name: /log in/i });
        const form = button.closest('form');
        if (!form) throw new Error('Form not found');
        fireEvent.submit(form);
        expect(await screen.findByText('Invalid credentials')).toBeInTheDocument();
    });

    it.each(['https://evil.com', '//evil.com'])(
        'falls back to root for invalid redirect %s',
        async (redirectValue) => {
            vi.stubGlobal('fetch', vi.fn(async () => ({
                ok: true,
                json: async () => ({ redirect: redirectValue }),
            }))); 
            const assignSpy = vi.fn();
            Object.defineProperty(window, 'location', {
                value: { assign: assignSpy, origin: 'http://localhost' },
            });
            render(<Login canResetPassword={false} />);
            fireEvent.input(screen.getByLabelText(/email address/i), {
                target: { value: 'user@example.com' },
            });
            fireEvent.input(screen.getByLabelText(/password/i), {
                target: { value: 'password' },
            });
            const button = screen.getByRole('button', { name: /log in/i });
            const form = button.closest('form');
            if (!form) throw new Error('Form not found');
            fireEvent.submit(form);
            await waitFor(() => expect(assignSpy).toHaveBeenCalledWith('/'));
        },
    );
});

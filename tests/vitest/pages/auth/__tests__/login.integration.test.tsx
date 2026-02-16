import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Login from '@/pages/auth/login';

const { routerMock } = vi.hoisted(() => ({
    routerMock: {
        post: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ href, children }: { href: string; children?: React.ReactNode }) => <a href={href}>{children}</a>,
    router: routerMock,
}));

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController', () => ({
    default: { store: { url: () => '/login' } },
}));

vi.mock('@/routes/password', () => ({
    request: () => '/forgot-password',
}));

vi.mock('@/components/text-link', () => ({
    default: ({ href, children }: { href: string; children?: React.ReactNode }) => <a href={href}>{children}</a>,
}));

beforeEach(() => {
    routerMock.post.mockReset();
});

describe('Login integration', () => {
    it('submits login data via router.post on successful form submission', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<Login canResetPassword={false} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/email address/i), 'user@example.com');
        await user.type(screen.getByLabelText(/password/i), 'password');
        await user.click(screen.getByRole('button', { name: /log in/i }));

        await waitFor(() => {
            expect(routerMock.post).toHaveBeenCalledWith(
                '/login',
                expect.objectContaining({ email: 'user@example.com', password: 'password' }),
                expect.any(Object),
            );
        });
    });

    it('shows error message when credentials are invalid', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options?.onError?.({ email: 'Invalid credentials' });
            options?.onFinish?.();
        });
        render(<Login canResetPassword={false} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/email address/i), 'wrong@example.com');
        await user.type(screen.getByLabelText(/password/i), 'bad');
        await user.click(screen.getByRole('button', { name: /log in/i }));

        await waitFor(() => {
            expect(screen.getByText('Invalid credentials')).toBeInTheDocument();
        });
    });

    it('includes remember field when checkbox is checked', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<Login canResetPassword={false} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/email address/i), 'user@example.com');
        await user.type(screen.getByLabelText(/password/i), 'password');
        await user.click(screen.getByLabelText(/remember me/i));
        await user.click(screen.getByRole('button', { name: /log in/i }));

        await waitFor(() => {
            expect(routerMock.post).toHaveBeenCalledWith(
                '/login',
                expect.objectContaining({ remember: true }),
                expect.any(Object),
            );
        });
    });

    it('button is re-enabled after onFinish is called', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<Login canResetPassword={false} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/email address/i), 'user@example.com');
        await user.type(screen.getByLabelText(/password/i), 'password');
        await user.click(screen.getByRole('button', { name: /log in/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /log in/i })).not.toBeDisabled();
        });
    });
});

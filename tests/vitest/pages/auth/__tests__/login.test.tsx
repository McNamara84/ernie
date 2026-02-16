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

    it('renders validation errors on submit with empty fields', async () => {
        render(<Login canResetPassword={false} />);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /log in/i }));

        await waitFor(() => {
            expect(screen.getByText(/please enter a valid email address/i)).toBeInTheDocument();
            expect(screen.getByText(/password is required/i)).toBeInTheDocument();
        });
    });

    it('disables the submit button while processing', async () => {
        routerMock.post.mockImplementation(() => {});
        render(<Login canResetPassword={false} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/email address/i), 'user@example.com');
        await user.type(screen.getByLabelText(/password/i), 'password');
        await user.click(screen.getByRole('button', { name: /log in/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /log in/i })).toBeDisabled();
        });
    });

    it('calls router.post on successful submit', async () => {
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

    it('shows server error when returned via onError', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options?.onError?.({ email: 'Invalid credentials' });
            options?.onFinish?.();
        });
        render(<Login canResetPassword={false} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/email address/i), 'user@example.com');
        await user.type(screen.getByLabelText(/password/i), 'password');
        await user.click(screen.getByRole('button', { name: /log in/i }));

        await waitFor(() => {
            expect(screen.getByText('Invalid credentials')).toBeInTheDocument();
        });
    });
});

import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ResetPassword from '@/pages/auth/reset-password';

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

vi.mock('@/actions/App/Http/Controllers/Auth/NewPasswordController', () => ({
    default: { store: { url: () => '/reset-password' } },
}));

beforeEach(() => {
    routerMock.post.mockReset();
});

describe('ResetPassword integration', () => {
    const token = 'token123';
    const email = 'user@example.com';

    it('submits form with token via router.post', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<ResetPassword token={token} email={email} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText('Password'), 'newpassword');
        await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
        await user.click(screen.getByRole('button', { name: /reset password/i }));

        await waitFor(() => {
            expect(routerMock.post).toHaveBeenCalledWith(
                '/reset-password',
                expect.objectContaining({ email, password: 'newpassword', token }),
                expect.any(Object),
            );
        });
    });

    it('shows server errors returned via onError', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options?.onError?.({ password: 'Too short' });
            options?.onFinish?.();
        });
        render(<ResetPassword token={token} email={email} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText('Password'), 'newpassword');
        await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
        await user.click(screen.getByRole('button', { name: /reset password/i }));

        await waitFor(() => {
            expect(screen.getByText('Too short')).toBeInTheDocument();
        });
    });

    it('re-enables button after onFinish', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<ResetPassword token={token} email={email} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText('Password'), 'newpassword');
        await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
        await user.click(screen.getByRole('button', { name: /reset password/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /reset password/i })).not.toBeDisabled();
        });
    });

    it('transmits the plain password regardless of visibility toggle state (AC #4)', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<ResetPassword token={token} email={email} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText('Password'), 'newpassword');
        await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');

        // Toggle both fields to visible before submission
        await user.click(screen.getByRole('button', { name: 'Show password' }));
        await user.click(screen.getByRole('button', { name: 'Show password confirmation' }));

        await user.click(screen.getByRole('button', { name: /reset password/i }));

        await waitFor(() => {
            expect(routerMock.post).toHaveBeenCalledWith(
                '/reset-password',
                expect.objectContaining({
                    email,
                    password: 'newpassword',
                    password_confirmation: 'newpassword',
                    token,
                }),
                expect.any(Object),
            );
        });
    });
});

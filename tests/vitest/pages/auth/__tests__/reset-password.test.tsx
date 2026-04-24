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
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    router: routerMock,
}));

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/NewPasswordController', () => ({
    default: { store: { url: () => '/reset-password' } },
}));

describe('ResetPassword page', () => {
    const token = 'token123';
    const email = 'user@example.com';

    beforeEach(() => {
        routerMock.post.mockReset();
    });

    it('renders fields and submit button with preset email', () => {
        render(<ResetPassword token={token} email={email} />);
        expect(screen.getByLabelText(/email/i)).toHaveValue(email);
        expect(screen.getByLabelText('Password')).toBeInTheDocument();
        expect(screen.getByLabelText(/confirm password/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /reset password/i })).toBeInTheDocument();
    });

    it('displays validation errors on submit with empty password', async () => {
        render(<ResetPassword token={token} email={email} />);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /reset password/i }));

        await waitFor(() => {
            expect(screen.getByText(/password must be at least 8 characters/i)).toBeInTheDocument();
        });
    });

    it('disables submit button when processing', async () => {
        routerMock.post.mockImplementation(() => {});
        render(<ResetPassword token={token} email={email} />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText('Password'), 'newpassword');
        await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
        await user.click(screen.getByRole('button', { name: /reset password/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /reset password/i })).toBeDisabled();
        });
    });

    describe('password visibility toggle (issue #380)', () => {
        it('renders both password inputs with type="password" by default', () => {
            render(<ResetPassword token={token} email={email} />);
            expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password');
            expect(screen.getByLabelText(/confirm password/i)).toHaveAttribute('type', 'password');
        });

        it('renders a distinct show-toggle button for each password field', () => {
            render(<ResetPassword token={token} email={email} />);
            expect(screen.getByRole('button', { name: 'Show password' })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Show password confirmation' })).toBeInTheDocument();
        });

        it('toggles only the password field when its icon is clicked', async () => {
            render(<ResetPassword token={token} email={email} />);
            const user = userEvent.setup();

            await user.click(screen.getByRole('button', { name: 'Show password' }));

            expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'text');
            expect(screen.getByLabelText(/confirm password/i)).toHaveAttribute('type', 'password');
            expect(screen.getByRole('button', { name: 'Hide password' })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Show password confirmation' })).toBeInTheDocument();
        });

        it('toggles only the confirm-password field when its icon is clicked', async () => {
            render(<ResetPassword token={token} email={email} />);
            const user = userEvent.setup();

            await user.click(screen.getByRole('button', { name: 'Show password confirmation' }));

            expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password');
            expect(screen.getByLabelText(/confirm password/i)).toHaveAttribute('type', 'text');
            expect(screen.getByRole('button', { name: 'Show password' })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Hide password confirmation' })).toBeInTheDocument();
        });

        it('hides the password again when the toggle is clicked twice', async () => {
            render(<ResetPassword token={token} email={email} />);
            const user = userEvent.setup();

            const toggle = screen.getByRole('button', { name: 'Show password' });
            await user.click(toggle);
            await user.click(screen.getByRole('button', { name: 'Hide password' }));

            expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password');
            expect(screen.getByRole('button', { name: 'Show password' })).toBeInTheDocument();
        });

        it('does not submit the form when a toggle is activated', async () => {
            render(<ResetPassword token={token} email={email} />);
            const user = userEvent.setup();

            await user.click(screen.getByRole('button', { name: 'Show password' }));
            await user.click(screen.getByRole('button', { name: 'Show password confirmation' }));

            expect(routerMock.post).not.toHaveBeenCalled();
        });

        it('preserves the typed value across visibility toggles', async () => {
            render(<ResetPassword token={token} email={email} />);
            const user = userEvent.setup();

            const input = screen.getByLabelText('Password') as HTMLInputElement;
            await user.type(input, 'MySecret#1');
            expect(input).toHaveValue('MySecret#1');

            await user.click(screen.getByRole('button', { name: 'Show password' }));
            expect(screen.getByLabelText('Password')).toHaveValue('MySecret#1');

            await user.click(screen.getByRole('button', { name: 'Hide password' }));
            expect(screen.getByLabelText('Password')).toHaveValue('MySecret#1');
        });

        it('is keyboard accessible (toggle reachable via Tab and activatable via Space)', async () => {
            render(<ResetPassword token={token} email={email} />);
            const user = userEvent.setup();

            screen.getByLabelText('Password').focus();
            await user.tab();
            const toggle = screen.getByRole('button', { name: 'Show password' });
            expect(toggle).toHaveFocus();

            await user.keyboard(' ');
            expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'text');
        });

        it('renders toggle buttons with type="button" so they do not submit the form', () => {
            render(<ResetPassword token={token} email={email} />);
            expect(screen.getByRole('button', { name: 'Show password' })).toHaveAttribute('type', 'button');
            expect(screen.getByRole('button', { name: 'Show password confirmation' })).toHaveAttribute('type', 'button');
        });
    });
});

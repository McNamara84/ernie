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
});

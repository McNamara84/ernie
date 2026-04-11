import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Password from '@/pages/settings/password';

const { routerMock, feedbackMock } = vi.hoisted(() => ({
    routerMock: {
        put: vi.fn(),
    },
    feedbackMock: {
        saved: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
}));

vi.mock('@/lib/feedback', () => ({
    feedback: feedbackMock,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/routes/password', () => ({
    edit: () => ({ url: '/settings/password' }),
}));

vi.mock('@/actions/App/Http/Controllers/Settings/PasswordController', () => ({
    default: { update: { url: () => '/settings/password' } },
}));

describe('Password settings page', () => {
    beforeEach(() => {
        routerMock.put.mockReset();
        feedbackMock.saved.mockReset();
    });

    it('renders fields for updating password', () => {
        render(<Password />);
        expect(screen.getByLabelText(/current password/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/^new password$/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/confirm password/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /save password/i })).toBeInTheDocument();
    });

    it('shows validation errors on submit with empty fields', async () => {
        render(<Password />);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /save password/i }));

        await waitFor(() => {
            expect(screen.getByText(/current password is required/i)).toBeInTheDocument();
            expect(screen.getByText(/password must be at least 8 characters/i)).toBeInTheDocument();
        });
    });

    it('disables button when processing and shows success message', async () => {
        routerMock.put.mockImplementation((_url: string, _data: unknown, options?: { onSuccess?: () => void; onFinish?: () => void }) => {
            options?.onSuccess?.();
            options?.onFinish?.();
        });
        render(<Password />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/current password/i), 'oldpassword');
        await user.type(screen.getByLabelText(/^new password$/i), 'newpassword');
        await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
        await user.click(screen.getByRole('button', { name: /save password/i }));

        await waitFor(() => {
            expect(screen.getByText(/saved/i)).toBeInTheDocument();
            expect(feedbackMock.saved).toHaveBeenCalledWith('Password');
        });
    });

    it('shows server-side errors on the correct fields', async () => {
        routerMock.put.mockImplementation(
            (_url: string, _data: unknown, options?: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
                options?.onError?.({
                    current_password: 'The current password is incorrect.',
                    password: 'The password has already been used.',
                });
                options?.onFinish?.();
            },
        );
        render(<Password />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/current password/i), 'wrongpassword');
        await user.type(screen.getByLabelText(/^new password$/i), 'newpassword');
        await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
        await user.click(screen.getByRole('button', { name: /save password/i }));

        await waitFor(() => {
            expect(screen.getByText(/the current password is incorrect/i)).toBeInTheDocument();
            expect(screen.getByText(/the password has already been used/i)).toBeInTheDocument();
        });
    });
});

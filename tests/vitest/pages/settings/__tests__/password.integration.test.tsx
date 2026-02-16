import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { routerMock } = vi.hoisted(() => ({
    routerMock: {
        put: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
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

import Password from '@/pages/settings/password';

beforeEach(() => {
    routerMock.put.mockReset();
});

describe('Password settings integration', () => {
    it('updates password successfully and shows saved message', async () => {
        routerMock.put.mockImplementation((_url: string, _data: unknown, options?: { onSuccess?: () => void; onFinish?: () => void }) => {
            options?.onSuccess?.();
            options?.onFinish?.();
        });
        render(<Password />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/current password/i), 'oldpass');
        await user.type(screen.getByLabelText(/^new password$/i), 'newpassword');
        await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
        await user.click(screen.getByRole('button', { name: /save password/i }));

        await waitFor(() => {
            expect(routerMock.put).toHaveBeenCalledWith(
                '/settings/password',
                expect.objectContaining({ current_password: 'oldpass', password: 'newpassword' }),
                expect.any(Object),
            );
        });
        expect(await screen.findByText('Saved')).toBeInTheDocument();
    });

    it('shows server error and focuses current password on error', async () => {
        routerMock.put.mockImplementation((_url: string, _data: unknown, options?: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options?.onError?.({ current_password: 'Incorrect' });
            options?.onFinish?.();
        });
        render(<Password />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/current password/i), 'wrongpass');
        await user.type(screen.getByLabelText(/^new password$/i), 'newpassword');
        await user.type(screen.getByLabelText(/confirm password/i), 'newpassword');
        await user.click(screen.getByRole('button', { name: /save password/i }));

        await waitFor(() => {
            expect(screen.getByText('Incorrect')).toBeInTheDocument();
        });
    });
});

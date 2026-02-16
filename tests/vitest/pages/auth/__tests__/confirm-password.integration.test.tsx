import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ConfirmPassword from '@/pages/auth/confirm-password';

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

vi.mock('@/actions/App/Http/Controllers/Auth/ConfirmablePasswordController', () => ({
    default: { store: { url: () => '/confirm-password' } },
}));

vi.mock('@/routes', () => ({
    home: () => '/',
}));

beforeEach(() => {
    routerMock.post.mockReset();
});

describe('ConfirmPassword integration', () => {
    it('submits password via router.post', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<ConfirmPassword />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/password/i), 'password');
        await user.click(screen.getByRole('button', { name: /confirm password/i }));

        await waitFor(() => {
            expect(routerMock.post).toHaveBeenCalledWith(
                '/confirm-password',
                expect.objectContaining({ password: 'password' }),
                expect.any(Object),
            );
        });
    });

    it('shows server error message on failure', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options?.onError?.({ password: 'Invalid password' });
            options?.onFinish?.();
        });
        render(<ConfirmPassword />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/password/i), 'wrong');
        await user.click(screen.getByRole('button', { name: /confirm password/i }));

        await waitFor(() => {
            expect(screen.getByText('Invalid password')).toBeInTheDocument();
        });
    });

    it('re-enables button after onFinish', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<ConfirmPassword />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/password/i), 'password');
        await user.click(screen.getByRole('button', { name: /confirm password/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /confirm password/i })).not.toBeDisabled();
        });
    });
});

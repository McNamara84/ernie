import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ForgotPassword from '@/pages/auth/forgot-password';

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

vi.mock('@/actions/App/Http/Controllers/Auth/PasswordResetLinkController', () => ({
    default: { store: { url: () => '/forgot-password' } },
}));

vi.mock('@/routes', () => ({
    login: () => '/login',
}));

vi.mock('@/components/text-link', () => ({
    default: ({ href, children }: { href: string; children?: React.ReactNode }) => <a href={href}>{children}</a>,
}));

beforeEach(() => {
    routerMock.post.mockReset();
});

describe('ForgotPassword integration', () => {
    it('submits email via router.post', async () => {
        routerMock.post.mockImplementation((_url: string, _data: unknown, options?: { onFinish?: () => void }) => {
            options?.onFinish?.();
        });
        render(<ForgotPassword />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/email address/i), 'user@example.com');
        await user.click(screen.getByRole('button', { name: /email password reset link/i }));

        await waitFor(() => {
            expect(routerMock.post).toHaveBeenCalledWith(
                '/forgot-password',
                expect.objectContaining({ email: 'user@example.com' }),
                expect.any(Object),
            );
        });
    });
});

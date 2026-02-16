import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { authUser, routerMock } = vi.hoisted(() => ({
    authUser: { name: 'John Doe', email: 'john@example.com', email_verified_at: null },
    routerMock: {
        patch: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ href, children }: { href: string; children?: React.ReactNode }) => <a href={href}>{children}</a>,
    usePage: () => ({ props: { auth: { user: authUser } } }),
    router: routerMock,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/delete-user', () => ({
    default: () => <div />,
}));

vi.mock('@/routes/profile', () => ({
    edit: () => ({ url: '/settings/profile' }),
}));

vi.mock('@/routes/verification', () => ({
    send: () => '/email/verification-notification',
}));

vi.mock('@/actions/App/Http/Controllers/Settings/ProfileController', () => ({
    default: { update: { url: () => '/settings/profile' } },
}));

import Profile from '@/pages/settings/profile';

beforeEach(() => {
    routerMock.patch.mockReset();
});

describe('Profile settings integration', () => {
    it('submits profile successfully and shows saved message', async () => {
        routerMock.patch.mockImplementation((_url: string, _data: unknown, options?: { onSuccess?: () => void; onFinish?: () => void }) => {
            options?.onSuccess?.();
            options?.onFinish?.();
        });
        render(<Profile mustVerifyEmail={false} />);
        const user = userEvent.setup();

        await user.clear(screen.getByLabelText(/name/i));
        await user.type(screen.getByLabelText(/name/i), 'Jane Doe');
        await user.click(screen.getByRole('button', { name: /^save$/i }));

        await waitFor(() => {
            expect(routerMock.patch).toHaveBeenCalledWith(
                '/settings/profile',
                expect.objectContaining({ name: 'Jane Doe' }),
                expect.any(Object),
            );
        });
        expect(await screen.findByText('Saved')).toBeInTheDocument();
    });

    it('shows server validation errors', async () => {
        routerMock.patch.mockImplementation((_url: string, _data: unknown, options?: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options?.onError?.({ name: 'Required' });
            options?.onFinish?.();
        });
        render(<Profile mustVerifyEmail={false} />);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /^save$/i }));

        await waitFor(() => {
            expect(screen.getByText('Required')).toBeInTheDocument();
        });
    });
});

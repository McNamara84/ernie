import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Profile from '@/pages/settings/profile';

const { authUser, routerMock } = vi.hoisted(() => ({
    authUser: { name: 'John Doe', email: 'john@example.com', email_verified_at: null },
    routerMock: {
        patch: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ children, href, as, onClick }: { children?: React.ReactNode; href: string; as?: string; onClick?: () => void }) => (
        <a href={href} data-as={as} onClick={onClick}>
            {children}
        </a>
    ),
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
    default: () => <div data-testid="delete-user" />,
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

describe('Profile settings page', () => {
    beforeEach(() => {
        routerMock.patch.mockReset();
    });

    it('renders profile form with user data and verification link', () => {
        render(<Profile mustVerifyEmail={true} />);
        expect(screen.getByRole('heading', { name: /profile information/i })).toBeInTheDocument();
        expect(screen.getByLabelText(/name/i)).toHaveValue(authUser.name);
        expect(screen.getByLabelText(/email address/i)).toHaveValue(authUser.email);
        const resendLink = screen.getByText(/click here to resend the verification email/i);
        expect(resendLink).toHaveAttribute('href', '/email/verification-notification');
    });

    it('shows verification sent message', () => {
        render(<Profile mustVerifyEmail={true} status="verification-link-sent" />);
        expect(
            screen.getByText(/a new verification link has been sent to your email address/i),
        ).toBeInTheDocument();
    });

    it('shows validation errors on submit with invalid data', async () => {
        render(<Profile mustVerifyEmail={false} />);
        const user = userEvent.setup();

        // Clear name to trigger Zod min(2) validation
        await user.clear(screen.getByLabelText(/name/i));

        // Submit the form
        await user.click(screen.getByRole('button', { name: /^save$/i }));

        await waitFor(() => {
            expect(screen.getByText(/name must be at least 2 characters/i)).toBeInTheDocument();
        });
    });

    it('disables save button when processing', async () => {
        // Mock router.patch to not call onFinish, keeping processing=true
        routerMock.patch.mockImplementation(() => {});

        render(<Profile mustVerifyEmail={false} />);
        const user = userEvent.setup();

        // Submit with valid default data so Zod passes
        await user.click(screen.getByRole('button', { name: /^save$/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /^save$/i })).toBeDisabled();
        });
    });
});

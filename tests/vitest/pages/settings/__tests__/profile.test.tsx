import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Profile from '@/pages/settings/profile';

let processing = false;
let formErrors: Record<string, string> = {};
let recentlySuccessful = false;
const authUser = { name: 'John Doe', email: 'john@example.com', email_verified_at: null };

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ children, href, as, onClick }: { children?: React.ReactNode; href: string; as?: string; onClick?: () => void }) => (
        <a href={href} data-as={as} onClick={onClick}>
            {children}
        </a>
    ),
    Form: ({ children }: { children: (args: { processing: boolean; recentlySuccessful: boolean; errors: typeof formErrors }) => React.ReactNode }) => (
        <form>{children({ processing, recentlySuccessful, errors: formErrors })}</form>
    ),
    usePage: () => ({ props: { auth: { user: authUser } } }),
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
    default: { update: { form: () => ({}) } },
}));

describe('Profile settings page', () => {
    beforeEach(() => {
        processing = false;
        formErrors = {};
        recentlySuccessful = false;
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

    it('shows validation errors', () => {
        formErrors = { name: 'Name is required', email: 'Email is invalid' };
        render(<Profile mustVerifyEmail={false} />);
        expect(screen.getByText(/name is required/i)).toBeInTheDocument();
        expect(screen.getByText(/email is invalid/i)).toBeInTheDocument();
    });

    it('disables save button when processing', () => {
        processing = true;
        render(<Profile mustVerifyEmail={false} />);
        expect(screen.getByRole('button', { name: /^save$/i })).toBeDisabled();
    });
});


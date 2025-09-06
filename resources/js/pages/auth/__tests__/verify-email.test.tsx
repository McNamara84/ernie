import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import VerifyEmail from '../verify-email';
import { beforeEach, describe, expect, it, vi } from 'vitest';

let formProcessing: boolean;

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Form: ({ children }: { children: (args: { processing: boolean }) => React.ReactNode }) => (
        <form>{children({ processing: formProcessing })}</form>
    ),
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

vi.mock('@/actions/App/Http/Controllers/Auth/EmailVerificationNotificationController', () => ({
    default: {
        store: {
            form: () => ({}),
        },
    },
}));

vi.mock('@/routes', () => ({
    logout: () => '/logout',
    home: () => '/',
}));

describe('VerifyEmail page', () => {
    beforeEach(() => {
        formProcessing = false;
    });

    it('shows status message when verification link is sent', () => {
        render(<VerifyEmail status="verification-link-sent" />);
        expect(
            screen.getByText(
                /a new verification link has been sent to the email address you provided during registration./i,
            ),
        ).toBeInTheDocument();
    });

    it('disables resend button when processing', () => {
        formProcessing = true;
        render(<VerifyEmail />);
        expect(screen.getByRole('button', { name: /resend verification email/i })).toBeDisabled();
    });

    it('links to logout route', () => {
        render(<VerifyEmail />);
        expect(screen.getByRole('link', { name: /log out/i })).toHaveAttribute('href', '/logout');
    });
});


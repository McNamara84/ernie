import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import VerifyEmail from '@/pages/auth/verify-email';

let formProcessing: boolean;

function resolveHref(href: unknown): string {
    if (typeof href === 'string') {
        return href;
    }

    if (href && typeof href === 'object' && 'url' in href && typeof (href as { url?: unknown }).url === 'string') {
        return (href as { url: string }).url;
    }

    return '';
}

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Form: ({ children }: { children: (args: { processing: boolean }) => React.ReactNode }) => (
        <form>{children({ processing: formProcessing })}</form>
    ),
    Link: ({ children, href }: { children?: React.ReactNode; href: unknown }) => (
        <a href={resolveHref(href)}>{children}</a>
    ),
}));

vi.mock('@/actions/App/Http/Controllers/Auth/EmailVerificationNotificationController', () => ({
    default: {
        store: {
            post: () => ({}),
        },
    },
}));

function createRoute(path: string) {
    const routeFn = () => ({ url: path });
    routeFn.url = () => path;
    return routeFn;
}

vi.mock('@/routes', () => ({
    logout: createRoute('/logout'),
    home: createRoute('/'),
    about: createRoute('/about'),
    legalNotice: createRoute('/legal-notice'),
    changelog: createRoute('/changelog'),
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

    it('enables resend button by default and hides status message', () => {
        render(<VerifyEmail />);
        expect(screen.getByRole('button', { name: /resend verification email/i })).not.toBeDisabled();
        expect(
            screen.queryByText(
                /a new verification link has been sent to the email address you provided during registration./i,
            ),
        ).not.toBeInTheDocument();
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

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

function resolveHref(href: unknown): string {
    if (typeof href === 'string') return href;
    if (href && typeof href === 'object' && 'url' in href && typeof (href as { url?: unknown }).url === 'string') {
        return (href as { url: string }).url;
    }
    return '';
}

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ children, href }: { children?: React.ReactNode; href: unknown }) => <a href={resolveHref(href)}>{children}</a>,
    router: routerMock,
}));

vi.mock('@/layouts/auth-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

function createRoute(path: string) {
    const routeFn = () => ({ url: path });
    routeFn.url = () => path;
    return routeFn;
}

vi.mock('@/routes', () => ({
    home: createRoute('/'),
    about: createRoute('/about'),
    legalNotice: createRoute('/legal-notice'),
    changelog: createRoute('/changelog'),
}));

vi.mock('@/actions/App/Http/Controllers/Auth/ConfirmablePasswordController', () => ({
    default: { store: { url: () => '/confirm-password' } },
}));

describe('ConfirmPassword page', () => {
    beforeEach(() => {
        routerMock.post.mockReset();
    });

    it('renders password field and submit button', () => {
        render(<ConfirmPassword />);
        expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /confirm password/i })).toBeInTheDocument();
    });

    it('shows validation error on submit with empty password', async () => {
        render(<ConfirmPassword />);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: /confirm password/i }));

        await waitFor(() => {
            expect(screen.getByText(/password is required/i)).toBeInTheDocument();
        });
    });

    it('disables submit button when processing', async () => {
        routerMock.post.mockImplementation(() => {});
        render(<ConfirmPassword />);
        const user = userEvent.setup();

        await user.type(screen.getByLabelText(/password/i), 'password');
        await user.click(screen.getByRole('button', { name: /confirm password/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /confirm password/i })).toBeDisabled();
        });
    });
});

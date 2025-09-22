import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import ConfirmPassword from '../confirm-password';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const formErrors: { password?: string } = {};
let formProcessing = false;

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
    Form: ({
        children,
    }: {
        children: (args: { processing: boolean; errors: typeof formErrors }) => React.ReactNode;
    }) => <form>{children({ processing: formProcessing, errors: formErrors })}</form>,
    Link: ({ children, href }: { children?: React.ReactNode; href: unknown }) => (
        <a href={resolveHref(href)}>{children}</a>
    ),
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
    default: {
        store: {
            form: () => ({}),
        },
    },
}));

describe('ConfirmPassword page', () => {
    beforeEach(() => {
        formErrors.password = undefined;
        formProcessing = false;
    });

    it('renders password field and submit button', () => {
        render(<ConfirmPassword />);
        expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /confirm password/i })).toBeInTheDocument();
    });

    it('shows validation error', () => {
        formErrors.password = 'Required';
        render(<ConfirmPassword />);
        expect(screen.getByText(/required/i)).toBeInTheDocument();
    });

    it('disables submit button when processing', () => {
        formProcessing = true;
        render(<ConfirmPassword />);
        expect(screen.getByRole('button', { name: /confirm password/i })).toBeDisabled();
    });
});


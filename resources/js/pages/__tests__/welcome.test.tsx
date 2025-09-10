import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import Welcome from '../welcome';
import { describe, expect, it, beforeEach, vi } from 'vitest';

// mock @inertiajs/react and routes used in the component
const usePageMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    usePage: () => usePageMock(),
}));

vi.mock('@/routes', () => ({
    dashboard: () => '/dashboard',
    login: () => '/login',
    about: () => '/about',
    legalNotice: () => '/legal-notice',
}));

describe('Welcome', () => {
    beforeEach(() => {
        usePageMock.mockReturnValue({ props: { auth: {} } });
    });

    it('renders the heading', () => {
        render(<Welcome />);
        expect(
            screen.getByRole('heading', {
                name: /ERNIE - Earth Research Notary for Information & Editing/i,
            }),
        ).toBeInTheDocument();
    });

    it('shows login link when user is unauthenticated', () => {
        render(<Welcome />);
        expect(screen.getByRole('link', { name: /log in/i })).toHaveAttribute('href', '/login');
    });

    it('shows dashboard link when user is authenticated', () => {
        usePageMock.mockReturnValue({ props: { auth: { user: { id: 1 } } } });
        render(<Welcome />);
        expect(screen.getByRole('link', { name: /dashboard/i })).toHaveAttribute('href', '/dashboard');
    });
});

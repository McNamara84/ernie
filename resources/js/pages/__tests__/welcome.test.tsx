import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import Welcome from '../welcome';

// mock @inertiajs/react and routes used in the component
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    usePage: () => ({ props: { auth: {} } }),
}));

vi.mock('@/routes', () => ({
    dashboard: () => '/dashboard',
    login: () => '/login',
}));

describe('Welcome', () => {
    it('renders the heading', () => {
        render(<Welcome />);
        expect(
            screen.getByRole('heading', {
                name: /ERNIE - Earth Research Notary for Information & Editing/i,
            }),
        ).toBeInTheDocument();
    });
});

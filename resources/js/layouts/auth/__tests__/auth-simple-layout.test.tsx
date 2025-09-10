import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AuthSimpleLayout from '../auth-simple-layout';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children?: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

vi.mock('@/routes', () => ({
    home: () => '/',
}));

describe('AuthSimpleLayout', () => {
    it('renders link to home with title, description and children', () => {
        render(
            <AuthSimpleLayout title="Sign in" description="Welcome">
                <p>Child content</p>
            </AuthSimpleLayout>,
        );

        expect(screen.getByRole('link', { name: /sign in/i })).toHaveAttribute('href', '/');
        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.getByText('Welcome')).toBeInTheDocument();
        expect(screen.getByText('Child content')).toBeInTheDocument();
    });
});


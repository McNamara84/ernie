import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import AuthCardLayout from '../auth-card-layout';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/components/app-logo-icon', () => ({
    default: () => <svg data-testid="logo" />,
}));

vi.mock('@/components/ui/card', () => ({
    Card: ({ children }: { children: React.ReactNode }) => <div data-testid="card">{children}</div>,
    CardContent: ({ children }: { children: React.ReactNode }) => <div data-testid="card-content">{children}</div>,
    CardHeader: ({ children }: { children: React.ReactNode }) => <div data-testid="card-header">{children}</div>,
    CardTitle: ({ children }: { children: React.ReactNode }) => <h2>{children}</h2>,
    CardDescription: ({ children }: { children: React.ReactNode }) => <p>{children}</p>,
}));

describe('AuthCardLayout', () => {
    it('renders logo, title, description and children', () => {
        render(
            <AuthCardLayout title="Sign In" description="Welcome">
                <div>Form</div>
            </AuthCardLayout>,
        );
        expect(screen.getByTestId('logo')).toBeInTheDocument();
        expect(screen.getByText('Sign In')).toBeInTheDocument();
        expect(screen.getByText('Welcome')).toBeInTheDocument();
        expect(screen.getByText('Form')).toBeInTheDocument();
    });
});


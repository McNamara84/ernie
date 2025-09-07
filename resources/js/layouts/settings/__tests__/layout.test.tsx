import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import SettingsLayout from '../layout';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
}));

vi.mock('@/components/heading', () => ({
    default: ({ title, description }: { title: string; description: string }) => (
        <header>
            <h1>{title}</h1>
            <p>{description}</p>
        </header>
    ),
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children, className }: { children?: React.ReactNode; className?: string }) => (
        <button className={className}>{children}</button>
    ),
}));

vi.mock('@/components/ui/separator', () => ({
    Separator: () => <hr />,
}));

vi.mock('@/routes', () => ({
    appearance: () => '/settings/appearance',
}));
vi.mock('@/routes/password', () => ({
    edit: () => '/settings/password',
}));
vi.mock('@/routes/profile', () => ({
    edit: () => '/settings/profile',
}));

describe('SettingsLayout', () => {
    it('returns null when window is undefined', () => {
        const g = globalThis as { window?: Window };
        const realWindow = g.window;
        delete g.window;
        const result = SettingsLayout({ children: <div /> });
        expect(result).toBeNull();
        g.window = realWindow;
    });

    it('renders navigation and highlights current path', () => {
        window.history.pushState({}, '', '/settings/profile');
        render(
            <SettingsLayout>
                <p>Profile Content</p>
            </SettingsLayout>
        );
        expect(screen.getByRole('heading', { name: /settings/i })).toBeInTheDocument();
        const profileLink = screen.getByRole('link', { name: /profile/i });
        const profileButton = profileLink.closest('button');
        expect(profileButton).toHaveClass('bg-muted');
        const passwordLink = screen.getByRole('link', { name: /password/i });
        const passwordButton = passwordLink.closest('button');
        expect(passwordButton).not.toHaveClass('bg-muted');
        expect(screen.getByText('Profile Content')).toBeInTheDocument();
    });
});

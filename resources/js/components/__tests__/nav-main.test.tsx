import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { NavMain } from '../nav-main';

vi.mock('@/components/ui/sidebar', () => ({
    SidebarGroup: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarGroupLabel: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarMenu: ({ children }: { children?: React.ReactNode }) => <ul>{children}</ul>,
    SidebarMenuButton: ({ children, isActive }: { children?: React.ReactNode; isActive?: boolean }) => (
        <div data-active={isActive}>{children}</div>
    ),
    SidebarMenuItem: ({ children }: { children?: React.ReactNode }) => <li>{children}</li>,
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    usePage: () => ({ url: '/dashboard' }),
}));

describe('NavMain', () => {
    it('renders navigation items and highlights active link', () => {
        render(
            <NavMain
                items={[
                    { title: 'Dashboard', href: '/dashboard' },
                    { title: 'Settings', href: '/settings' },
                ]}
            />
        );

        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('href', '/dashboard');
        expect(links[1]).toHaveAttribute('href', '/settings');

        const dashboardItem = screen.getByText('Dashboard').closest('[data-active]');
        expect(dashboardItem).toHaveAttribute('data-active', 'true');
        const settingsItem = screen.getByText('Settings').closest('[data-active]');
        expect(settingsItem).toHaveAttribute('data-active', 'false');
    });
});


import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { NavMain } from '@/components/nav-main';

vi.mock('@/components/ui/sidebar', () => ({
    SidebarGroup: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarGroupLabel: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarMenu: ({ children }: { children?: React.ReactNode }) => <ul>{children}</ul>,
    SidebarMenuButton: ({ children, isActive, disabled, className }: { children?: React.ReactNode; isActive?: boolean; disabled?: boolean; className?: string }) => (
        <div data-active={isActive} data-disabled={disabled} className={className}>{children}</div>
    ),
    SidebarMenuItem: ({ children }: { children?: React.ReactNode }) => <li>{children}</li>,
    SidebarSeparator: ({ className }: { className?: string }) => <hr data-testid="sidebar-separator" className={className} />,
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

    it('renders disabled items without links', () => {
        render(
            <NavMain
                items={[
                    { title: 'Dashboard', href: '/dashboard' },
                    { title: 'Disabled Item', href: '/disabled', disabled: true },
                ]}
            />
        );

        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(1);
        expect(links[0]).toHaveAttribute('href', '/dashboard');

        const disabledItem = screen.getByText('Disabled Item').closest('[data-disabled]');
        expect(disabledItem).toHaveAttribute('data-disabled', 'true');
        expect(disabledItem).toHaveClass('cursor-not-allowed', 'opacity-50');
    });

    it('renders separators between items when specified', () => {
        render(
            <NavMain
                items={[
                    { title: 'Dashboard', href: '/dashboard' },
                    { title: 'Settings', href: '/settings', separator: true },
                ]}
            />
        );

        const separators = screen.getAllByTestId('sidebar-separator');
        expect(separators).toHaveLength(1);
        expect(separators[0]).toHaveClass('my-2');
    });
});


import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { __testing as basePathTesting, withBasePath } from '@/lib/base-path';
import type { NavItem } from '@/types';

const NavMainMock = vi.hoisted(() =>
    vi.fn(({ items }: { items: NavItem[] }) => (
        <nav data-testid="nav-main">
            {items.map((item) => {
                const href = typeof item.href === 'string' ? item.href : item.href.url;
                return (
                    <div key={item.title}>
                        {item.disabled ? (
                            <span>{item.title}</span>
                        ) : (
                            <a href={href}>{item.title}</a>
                        )}
                    </div>
                );
            })}
        </nav>
    ))
);

const NavFooterMock = vi.hoisted(() =>
    vi.fn(({ items, className }: { items: NavItem[]; className?: string }) => (
        <footer data-testid="nav-footer" className={className}>
            {items.map((item) => {
                const href = typeof item.href === 'string' ? item.href : item.href.url;
                return (
                    <a key={item.title} href={href}>
                        {item.title}
                    </a>
                );
            })}
        </footer>
    ))
);

const NavUserMock = vi.hoisted(() => vi.fn(() => <div data-testid="nav-user" />));

vi.mock('@/components/nav-main', () => ({ NavMain: NavMainMock }));
vi.mock('@/components/nav-footer', () => ({ NavFooter: NavFooterMock }));
vi.mock('@/components/nav-user', () => ({ NavUser: NavUserMock }));
vi.mock('@/components/ui/sidebar', () => ({
    Sidebar: ({ children }: { children?: React.ReactNode }) => <aside>{children}</aside>,
    SidebarHeader: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarMenu: ({ children }: { children?: React.ReactNode }) => <ul>{children}</ul>,
    SidebarMenuItem: ({ children }: { children?: React.ReactNode }) => <li>{children}</li>,
    SidebarMenuButton: ({ children }: { children?: React.ReactNode }) => <button>{children}</button>,
    SidebarContent: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarFooter: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();
    return {
        ...actual,
        Link: ({ children, href }: { children?: React.ReactNode; href: string }) => (
            <a href={href}>{children}</a>
        ),
        usePage: () => ({
            props: {
                auth: {
                    user: {
                        id: 1,
                        name: 'Test User',
                        email: 'test@example.com',
                        role: 'admin',
                        can_manage_users: true,
                        can_register_production_doi: true,
                    },
                },
            },
        }),
    };
});
vi.mock('@/components/app-logo', () => ({
    default: () => <span>Logo</span>,
}));

describe('AppSidebar', () => {
    afterEach(() => {
        basePathTesting.setMetaBasePath('');
        basePathTesting.resetBasePathCache();
        vi.resetModules();
    });

    it('renders main and footer navigation with user section', async () => {
        const { AppSidebar } = await import('@/components/app-sidebar');
        
        render(<AppSidebar />);
        expect(NavMainMock).toHaveBeenCalled();
        
        const navMain = screen.getByTestId('nav-main');
        const dashboardLink = within(navMain).getByRole('link', { name: /dashboard/i });
        expect(dashboardLink).toHaveAttribute('href', withBasePath('/dashboard'));

        const editorLink = within(navMain).getByRole('link', { name: /^data editor$/i });
        expect(editorLink).toHaveAttribute('href', withBasePath('/editor'));

        const oldDatasetsLink = within(navMain).getByRole('link', { name: /old datasets/i });
        expect(oldDatasetsLink).toHaveAttribute('href', withBasePath('/old-datasets'));

        const resourcesLink = within(navMain).getByRole('link', { name: /resources/i });
        expect(resourcesLink).toHaveAttribute('href', withBasePath('/resources'));

        const mainArgs = NavMainMock.mock.calls[0][0];
        expect(mainArgs.items.map((i: NavItem) => i.title)).toEqual([
            'Dashboard',
            'Data Editor',
            'Old Datasets',
            'Statistics (old)',
            'Resources',
            'IGSNs',
            'IGSN Editor',
        ]);

        const footerArgs = NavFooterMock.mock.calls[0][0];
        expect(footerArgs.items.map((i: NavItem) => i.title)).toEqual([
            'Users',
            'Editor Settings',
            'Changelog',
            'Documentation',
        ]);
        expect(footerArgs.className).toBe('mt-auto');

        const navFooter = screen.getByTestId('nav-footer');
        const settingsLink = within(navFooter).getByRole('link', { name: /editor settings/i });
        expect(settingsLink).toHaveAttribute('href', withBasePath('/settings'));

        const changelogLink = within(navFooter).getByRole('link', { name: /changelog/i });
        expect(changelogLink).toHaveAttribute('href', withBasePath('/changelog'));

        const docsLink = within(navFooter).getByRole('link', { name: /documentation/i });
        expect(docsLink).toHaveAttribute('href', withBasePath('/docs'));

        expect(screen.getByTestId('nav-user')).toBeInTheDocument();
    });

    it('applies the base path to navigation links when configured', async () => {
        basePathTesting.setMetaBasePath('/ernie');
        basePathTesting.resetBasePathCache();

        vi.resetModules();
        const routes = await import('@/routes');
        const { applyBasePathToRoutes } = await import('@/lib/base-path');
        applyBasePathToRoutes({
            dashboard: routes.dashboard,
            settings: routes.settings,
            changelog: routes.changelog,
            docs: routes.docs,
        });
        const { AppSidebar } = await import('@/components/app-sidebar');

        render(<AppSidebar />);

        const navMain = screen.getByTestId('nav-main');
        expect(within(navMain).getByRole('link', { name: /^dashboard$/i })).toHaveAttribute('href', '/ernie/dashboard');
        expect(within(navMain).getByRole('link', { name: /^data editor$/i })).toHaveAttribute('href', '/ernie/editor');
        expect(within(navMain).getByRole('link', { name: /old datasets/i })).toHaveAttribute('href', '/ernie/old-datasets');
        expect(within(navMain).getByRole('link', { name: /resources/i })).toHaveAttribute('href', '/ernie/resources');
        
        const navFooter = screen.getByTestId('nav-footer');
        expect(within(navFooter).getByRole('link', { name: /changelog/i })).toHaveAttribute('href', '/ernie/changelog');
        expect(within(navFooter).getByRole('link', { name: /documentation/i })).toHaveAttribute('href', '/ernie/docs');
        expect(within(navFooter).getByRole('link', { name: /editor settings/i })).toHaveAttribute('href', '/ernie/settings');
    });
});


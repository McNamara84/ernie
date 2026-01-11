import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { NavItem } from '@/types';

// Configurable mock user - can be changed per test
let mockUser = {
    id: 1,
    name: 'Test User',
    email: 'test@example.com',
    role: 'admin',
    can_manage_users: true,
    can_register_production_doi: true,
    can_access_logs: true,
    can_access_old_datasets: true,
    can_access_statistics: true,
    can_access_users: true,
    can_access_editor_settings: true,
};

// Helper to set mock user for each test
const setMockUser = (
    overrides: Partial<{
        role: string;
        can_manage_users: boolean;
        can_access_logs: boolean;
        can_access_old_datasets: boolean;
        can_access_statistics: boolean;
        can_access_users: boolean;
        can_access_editor_settings: boolean;
    }> = {}
) => {
    mockUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
        role: 'admin',
        can_manage_users: true,
        can_register_production_doi: true,
        can_access_logs: true,
        can_access_old_datasets: true,
        can_access_statistics: true,
        can_access_users: true,
        can_access_editor_settings: true,
        ...overrides,
    };
};

const NavMainMock = vi.hoisted(() =>
    vi.fn(({ items }: { items: NavItem[] }) => (
        <nav data-testid="nav-main">
            {items.map((item) => {
                const href = typeof item.href === 'string' ? item.href : item.href.url;
                return (
                    <div key={item.title}>
                        {item.disabled ? <span>{item.title}</span> : <a href={href}>{item.title}</a>}
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

const NavSectionMock = vi.hoisted(() =>
    vi.fn(({ items, label }: { items: NavItem[]; label?: string }) => (
        <nav data-testid="nav-section">
            {label && <div>{label}</div>}
            {items.map((item) => {
                const href = typeof item.href === 'string' ? item.href : item.href.url;
                return (
                    <div key={item.title}>
                        {item.disabled ? <span>{item.title}</span> : <a href={href}>{item.title}</a>}
                    </div>
                );
            })}
        </nav>
    ))
);

vi.mock('@/components/nav-main', () => ({ NavMain: NavMainMock }));
vi.mock('@/components/nav-section', () => ({ NavSection: NavSectionMock }));
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
    SidebarGroup: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarGroupLabel: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    SidebarSeparator: () => <hr />,
}));

// Use a getter function so mockUser changes are reflected
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();
    return {
        ...actual,
        Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
        usePage: () => ({
            props: {
                auth: {
                    user: mockUser,
                },
            },
        }),
    };
});

vi.mock('@/components/app-logo', () => ({
    default: () => <span>Logo</span>,
}));

// Import component once - mocks are already set up
import { AppSidebar } from '@/components/app-sidebar';

describe('AppSidebar', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        // Reset to admin user by default
        setMockUser();
    });

    it('renders navigation sections with correct items for admin user', () => {
        setMockUser({
            role: 'admin',
            can_access_logs: true,
            can_access_old_datasets: true,
            can_access_statistics: true,
            can_access_users: true,
            can_access_editor_settings: true,
        });

        render(<AppSidebar />);

        // Should render NavSection components (4 sections for admin)
        expect(NavSectionMock).toHaveBeenCalled();
        expect(NavSectionMock).toHaveBeenCalledTimes(4);

        // Get all NavSection calls
        const sectionCalls = NavSectionMock.mock.calls;

        // Section 1: Dashboard (no label)
        expect(sectionCalls[0][0].items.map((i: NavItem) => i.title)).toEqual(['Dashboard']);
        expect(sectionCalls[0][0].label).toBeUndefined();

        // Section 2: Data Curation
        expect(sectionCalls[1][0].items.map((i: NavItem) => i.title)).toEqual(['Data Editor', 'Resources']);
        expect(sectionCalls[1][0].label).toBe('Data Curation');

        // Section 3: IGSN Curation
        expect(sectionCalls[2][0].items.map((i: NavItem) => i.title)).toEqual(['IGSNs', 'IGSN Editor']);
        expect(sectionCalls[2][0].label).toBe('IGSN Curation');

        // Section 4: Administration (only for admins)
        expect(sectionCalls[3][0].items.map((i: NavItem) => i.title)).toEqual([
            'Old Datasets',
            'Statistics (old)',
            'Users',
            'Logs',
        ]);
        expect(sectionCalls[3][0].label).toBe('Administration');

        // Check footer navigation
        expect(NavFooterMock).toHaveBeenCalled();
        const footerArgs = NavFooterMock.mock.calls[0][0];
        expect(footerArgs.items.map((i: NavItem) => i.title)).toEqual(['Editor Settings', 'Changelog', 'Documentation']);
        expect(footerArgs.className).toBe('mt-auto');

        // Check nav sections render links
        const navSections = screen.getAllByTestId('nav-section');
        expect(navSections.length).toBeGreaterThanOrEqual(3);

        // Verify links are present
        expect(screen.getByRole('link', { name: /dashboard/i })).toHaveAttribute('href', '/dashboard');
        expect(screen.getByRole('link', { name: /^data editor$/i })).toHaveAttribute('href', '/editor');
        expect(screen.getByRole('link', { name: /resources/i })).toHaveAttribute('href', '/resources');

        // Check footer links
        const navFooter = screen.getByTestId('nav-footer');
        expect(within(navFooter).getByRole('link', { name: /editor settings/i })).toHaveAttribute('href', '/settings');
        expect(within(navFooter).getByRole('link', { name: /changelog/i })).toHaveAttribute('href', '/changelog');
        expect(within(navFooter).getByRole('link', { name: /documentation/i })).toHaveAttribute('href', '/docs');

        // Check user section
        expect(screen.getByTestId('nav-user')).toBeInTheDocument();
    });

    it('does not render Administration section for non-admin users (beginner)', () => {
        // Mock beginner user without administration access (Issue #379)
        setMockUser({
            role: 'beginner',
            can_manage_users: false,
            can_access_logs: false,
            can_access_old_datasets: false,
            can_access_statistics: false,
            can_access_users: false,
            can_access_editor_settings: false,
        });

        render(<AppSidebar />);

        // Should render NavSection components (3 sections for non-admin - no Administration)
        expect(NavSectionMock).toHaveBeenCalled();
        expect(NavSectionMock).toHaveBeenCalledTimes(3);

        // Verify Administration section is NOT rendered
        const sectionCalls = NavSectionMock.mock.calls;
        const sectionLabels = sectionCalls.map((call) => call[0].label);
        expect(sectionLabels).not.toContain('Administration');

        // Verify the other sections are present
        expect(sectionLabels).toContain('Data Curation');
        expect(sectionLabels).toContain('IGSN Curation');

        // Verify Editor Settings is NOT in footer (Issue #379)
        const footerArgs = NavFooterMock.mock.calls[0][0];
        const footerTitles = footerArgs.items.map((i: NavItem) => i.title);
        expect(footerTitles).not.toContain('Editor Settings');
        expect(footerTitles).toContain('Changelog');
        expect(footerTitles).toContain('Documentation');
    });

    it('does not render Administration section for curator users', () => {
        // Mock curator user without administration access (Issue #379)
        setMockUser({
            role: 'curator',
            can_manage_users: false,
            can_access_logs: false,
            can_access_old_datasets: false,
            can_access_statistics: false,
            can_access_users: false,
            can_access_editor_settings: false,
        });

        render(<AppSidebar />);

        // Should render NavSection components (3 sections for curator - no Administration)
        expect(NavSectionMock).toHaveBeenCalled();
        expect(NavSectionMock).toHaveBeenCalledTimes(3);

        // Verify Administration section is NOT rendered
        const sectionCalls = NavSectionMock.mock.calls;
        const sectionLabels = sectionCalls.map((call) => call[0].label);
        expect(sectionLabels).not.toContain('Administration');

        // Verify Editor Settings is NOT in footer (Issue #379)
        const footerArgs = NavFooterMock.mock.calls[0][0];
        const footerTitles = footerArgs.items.map((i: NavItem) => i.title);
        expect(footerTitles).not.toContain('Editor Settings');
    });

    it('renders partial Administration section for group leader (Issue #379)', () => {
        // Mock group leader user with partial administration access
        setMockUser({
            role: 'group_leader',
            can_manage_users: true,
            can_access_logs: false,
            can_access_old_datasets: false,
            can_access_statistics: true,
            can_access_users: true,
            can_access_editor_settings: true,
        });

        render(<AppSidebar />);

        // Should render NavSection components (4 sections for group leader with partial admin)
        expect(NavSectionMock).toHaveBeenCalled();
        expect(NavSectionMock).toHaveBeenCalledTimes(4);

        // Verify Administration section IS rendered
        const sectionCalls = NavSectionMock.mock.calls;
        const adminSection = sectionCalls.find((call) => call[0].label === 'Administration');
        expect(adminSection).toBeDefined();

        // Verify only Statistics and Users are shown (not Logs or Old Datasets)
        const adminItems = adminSection![0].items.map((i: NavItem) => i.title);
        expect(adminItems).toContain('Statistics (old)');
        expect(adminItems).toContain('Users');
        expect(adminItems).not.toContain('Logs');
        expect(adminItems).not.toContain('Old Datasets');

        // Verify Editor Settings IS in footer for group leader
        const footerArgs = NavFooterMock.mock.calls[0][0];
        const footerTitles = footerArgs.items.map((i: NavItem) => i.title);
        expect(footerTitles).toContain('Editor Settings');
    });
});

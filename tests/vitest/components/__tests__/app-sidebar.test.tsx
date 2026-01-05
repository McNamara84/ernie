import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

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

const NavSectionMock = vi.hoisted(() =>
    vi.fn(({ items, label }: { items: NavItem[]; label?: string }) => (
        <nav data-testid="nav-section">
            {label && <div>{label}</div>}
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
                        can_access_administration: true,
                    },
                },
            },
        }),
    };
});
vi.mock('@/components/app-logo', () => ({
    default: () => <span>Logo</span>,
}));

// Helper to create mock page props for different user roles
const createMockUser = (overrides: Partial<{
    role: string;
    can_manage_users: boolean;
    can_access_administration: boolean;
}> = {}) => ({
    id: 1,
    name: 'Test User',
    email: 'test@example.com',
    role: 'admin',
    can_manage_users: true,
    can_register_production_doi: true,
    can_access_administration: true,
    ...overrides,
});

describe('AppSidebar', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders navigation sections with correct items for admin user', async () => {
        // Mock admin user
        vi.doMock('@inertiajs/react', async (importOriginal) => {
            const actual = await importOriginal<typeof import('@inertiajs/react')>();
            return {
                ...actual,
                Link: ({ children, href }: { children?: React.ReactNode; href: string }) => (
                    <a href={href}>{children}</a>
                ),
                usePage: () => ({
                    props: { auth: { user: createMockUser() } },
                }),
            };
        });

        const { AppSidebar } = await import('@/components/app-sidebar');

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

    it('does not render Administration section for non-admin users (beginner)', async () => {
        // Reset mocks for this test
        vi.clearAllMocks();

        // Mock beginner user without administration access
        vi.doMock('@inertiajs/react', async (importOriginal) => {
            const actual = await importOriginal<typeof import('@inertiajs/react')>();
            return {
                ...actual,
                Link: ({ children, href }: { children?: React.ReactNode; href: string }) => (
                    <a href={href}>{children}</a>
                ),
                usePage: () => ({
                    props: {
                        auth: {
                            user: createMockUser({
                                role: 'beginner',
                                can_manage_users: false,
                                can_access_administration: false,
                            }),
                        },
                    },
                }),
            };
        });

        // Need to re-import to get fresh module with new mock
        vi.resetModules();
        const { AppSidebar } = await import('@/components/app-sidebar');

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
    });

    it('does not render Administration section for curator users', async () => {
        // Reset mocks for this test
        vi.clearAllMocks();

        // Mock curator user without administration access
        vi.doMock('@inertiajs/react', async (importOriginal) => {
            const actual = await importOriginal<typeof import('@inertiajs/react')>();
            return {
                ...actual,
                Link: ({ children, href }: { children?: React.ReactNode; href: string }) => (
                    <a href={href}>{children}</a>
                ),
                usePage: () => ({
                    props: {
                        auth: {
                            user: createMockUser({
                                role: 'curator',
                                can_manage_users: false,
                                can_access_administration: false,
                            }),
                        },
                    },
                }),
            };
        });

        // Need to re-import to get fresh module with new mock
        vi.resetModules();
        const { AppSidebar } = await import('@/components/app-sidebar');

        render(<AppSidebar />);

        // Should render NavSection components (3 sections for curator - no Administration)
        expect(NavSectionMock).toHaveBeenCalled();
        expect(NavSectionMock).toHaveBeenCalledTimes(3);

        // Verify Administration section is NOT rendered
        const sectionCalls = NavSectionMock.mock.calls;
        const sectionLabels = sectionCalls.map((call) => call[0].label);
        expect(sectionLabels).not.toContain('Administration');
    });
});

import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { SIDEBAR_WORKSPACE_STORAGE_KEY } from '@/hooks/use-sidebar-workspace';
import type { AssessmentAverageSummary, NavItem } from '@/types';

let mockUrl = '/dashboard';

let mockUser = {
    id: 1,
    name: 'Test User',
    email: 'test@example.com',
    role: 'admin',
    can_manage_users: true,
    can_register_doi: true,
    can_register_production_doi: true,
    can_access_logs: true,
    can_access_old_datasets: true,
    can_access_statistics: true,
    can_access_users: true,
    can_access_editor_settings: true,
    can_manage_landing_page_templates: false,
    can_access_assistance: false,
    can_access_assessment: false,
};

type MockSharedProps = {
    assessmentAverageSummary: AssessmentAverageSummary | null;
    dataResourceCount: number | undefined;
    igsnCount: number | undefined;
    pendingAssistanceTotalCount: number | undefined;
};

let mockSharedProps: MockSharedProps = {
    assessmentAverageSummary: null,
    dataResourceCount: 12,
    igsnCount: 5,
    pendingAssistanceTotalCount: 0,
};

const setMockUser = (
    overrides: Partial<{
        role: string;
        can_manage_users: boolean;
        can_access_logs: boolean;
        can_access_old_datasets: boolean;
        can_access_statistics: boolean;
        can_access_users: boolean;
        can_access_editor_settings: boolean;
        can_manage_landing_page_templates: boolean;
        can_access_assistance: boolean;
        can_access_assessment: boolean;
    }> = {},
) => {
    mockUser = {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
        role: 'admin',
        can_manage_users: true,
        can_register_doi: true,
        can_register_production_doi: true,
        can_access_logs: true,
        can_access_old_datasets: true,
        can_access_statistics: true,
        can_access_users: true,
        can_access_editor_settings: true,
        can_manage_landing_page_templates: false,
        can_access_assistance: false,
        can_access_assessment: false,
        ...overrides,
    };
};

const setMockSharedProps = (overrides: Partial<MockSharedProps> = {}) => {
    mockSharedProps = {
        assessmentAverageSummary: null,
        dataResourceCount: 12,
        igsnCount: 5,
        pendingAssistanceTotalCount: 0,
        ...overrides,
    };
};

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
    )),
);

const NavUserMock = vi.hoisted(() => vi.fn(() => <div data-testid="nav-user" />));

const NavSectionMock = vi.hoisted(() =>
    vi.fn(({ items, label }: { items: NavItem[]; label?: string; showSeparator?: boolean }) => (
        <nav data-testid="nav-section">
            {label && <div>{label}</div>}
            {items.map((item) => {
                const href = typeof item.href === 'string' ? item.href : item.href.url;
                return (
                    <div key={item.title} data-badge={item.badge ?? ''} data-badge-tone={item.badgeTone ?? ''}>
                        {item.disabled ? <span>{item.title}</span> : <a href={href}>{item.title}</a>}
                    </div>
                );
            })}
        </nav>
    )),
);

const AppSidebarWorkspaceSwitcherMock = vi.hoisted(() =>
    vi.fn(({ value, onValueChange }: { value: 'curation' | 'administration'; onValueChange: (value: 'curation' | 'administration') => void }) => (
        <div data-testid="workspace-switcher" data-value={value}>
            <button type="button" onClick={() => onValueChange('curation')}>Switch to Curation</button>
            <button type="button" onClick={() => onValueChange('administration')}>Switch to Administration</button>
        </div>
    )),
);

vi.mock('@/components/nav-section', () => ({ NavSection: NavSectionMock }));
vi.mock('@/components/nav-footer', () => ({ NavFooter: NavFooterMock }));
vi.mock('@/components/nav-user', () => ({ NavUser: NavUserMock }));
vi.mock('@/components/app-sidebar-workspace-switcher', () => ({ AppSidebarWorkspaceSwitcher: AppSidebarWorkspaceSwitcherMock }));
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
        Link: ({ children, href }: { children?: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
        usePage: () => ({
            props: {
                auth: {
                    user: mockUser,
                },
                ...mockSharedProps,
            },
            url: mockUrl,
        }),
    };
});

vi.mock('@/components/app-logo', () => ({
    default: () => <span>Logo</span>,
}));

vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/dashboard' }),
    settings: () => ({ url: '/settings' }),
}));

import { AppSidebar } from '@/components/app-sidebar';

describe('AppSidebar', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        window.localStorage.clear();
        mockUrl = '/dashboard';
        setMockUser();
        setMockSharedProps();
    });

    it('renders the curation workspace by default for admin users on curation pages', () => {
        render(<AppSidebar />);

        expect(screen.getByTestId('workspace-switcher')).toHaveAttribute('data-value', 'curation');
        expect(NavSectionMock).toHaveBeenCalledTimes(3);

        const sectionCalls = NavSectionMock.mock.calls;
        expect(sectionCalls[0][0].label).toBeUndefined();
        expect(sectionCalls[0][0].items.map((item: NavItem) => item.title)).toEqual(['Dashboard']);
        expect(sectionCalls[1][0].label).toBe('Data Curation');
        expect(sectionCalls[1][0].items.map((item: NavItem) => item.title)).toEqual(['Data Editor', 'Resources', 'Portal']);
        expect(sectionCalls[1][0].items[2].href).toBe('/portal');
        expect(sectionCalls[1][0].items[2].openInNewTab).toBe(true);
        expect(sectionCalls[2][0].label).toBe('IGSN Curation');
        expect(sectionCalls[2][0].items.map((item: NavItem) => item.title)).toEqual(['IGSNs List', 'IGSNs Map', 'IGSN Editor']);

        const footer = screen.getByTestId('nav-footer');
        expect(within(footer).getByRole('link', { name: /changelog/i })).toHaveAttribute('href', '/changelog');
        expect(within(footer).getByRole('link', { name: /documentation/i })).toHaveAttribute('href', '/docs');
    });

    it('renders the administration workspace automatically on administration routes for admin users', () => {
        mockUrl = '/users';

        render(<AppSidebar />);

        expect(screen.getByTestId('workspace-switcher')).toHaveAttribute('data-value', 'administration');

        const sectionCalls = NavSectionMock.mock.calls;
        expect(sectionCalls.map((call) => call[0].label)).toEqual(['Team', 'Configuration', 'Operations', 'Legacy']);
        expect(sectionCalls[0][0].items.map((item: NavItem) => item.title)).toEqual(['Users']);
        expect(sectionCalls[1][0].items.map((item: NavItem) => item.title)).toEqual(['Editor Settings']);
        expect(sectionCalls[2][0].items.map((item: NavItem) => item.title)).toEqual(['Statistics', 'Logs']);
        expect(sectionCalls[3][0].items.map((item: NavItem) => item.title)).toEqual(['Old Datasets', 'Statistics (old)']);
    });

    it('renders the reduced administration workspace for group leaders', () => {
        mockUrl = '/users';
        setMockUser({
            role: 'group_leader',
            can_access_logs: false,
            can_access_old_datasets: false,
            can_access_statistics: true,
            can_access_users: true,
            can_access_editor_settings: true,
        });

        render(<AppSidebar />);

        expect(screen.getByTestId('workspace-switcher')).toHaveAttribute('data-value', 'administration');

        const sectionCalls = NavSectionMock.mock.calls;
        expect(sectionCalls.map((call) => call[0].label)).toEqual(['Team', 'Configuration', 'Operations', 'Legacy']);
        expect(sectionCalls[0][0].items.map((item: NavItem) => item.title)).toEqual(['Users']);
        expect(sectionCalls[1][0].items.map((item: NavItem) => item.title)).toEqual(['Editor Settings']);
        expect(sectionCalls[2][0].items.map((item: NavItem) => item.title)).toEqual(['Statistics']);
        expect(sectionCalls[3][0].items.map((item: NavItem) => item.title)).toEqual(['Statistics (old)']);
    });

    it('does not render a leading separator when the team section is filtered out', () => {
        mockUrl = '/settings';
        setMockUser({
            role: 'admin',
            can_access_users: false,
            can_access_logs: true,
            can_access_old_datasets: false,
            can_access_statistics: false,
            can_access_editor_settings: true,
            can_manage_landing_page_templates: false,
        });

        render(<AppSidebar />);

        const sectionCalls = NavSectionMock.mock.calls;
        expect(sectionCalls.map((call) => call[0].label)).toEqual(['Configuration', 'Operations']);
        expect(sectionCalls[0][0].showSeparator).toBe(false);
        expect(sectionCalls[1][0].showSeparator).toBe(true);
    });

    it('shows the assistance badge with warning tone in the administration workspace', () => {
        mockUrl = '/assistance';
        setMockUser({ can_access_assistance: true });
        setMockSharedProps({ pendingAssistanceTotalCount: 7 });

        render(<AppSidebar />);

        const operationsSection = NavSectionMock.mock.calls.find((call) => call[0].label === 'Operations');
        expect(operationsSection).toBeDefined();
        expect(operationsSection?.[0].items[0].title).toBe('Assistance');
        expect(operationsSection?.[0].items[0].badge).toBe(7);
        expect(operationsSection?.[0].items[0].badgeTone).toBe('warning');
    });

    it('shows assessment metrics in the administration workspace', () => {
        mockUrl = '/assessment';
        setMockUser({ can_access_assessment: true });
        setMockSharedProps({
            assessmentAverageSummary: {
                resources: 6.9,
                igsns: 3.2,
                formatted: '6.9 / 3.2',
            },
        });

        render(<AppSidebar />);

        const operationsSection = NavSectionMock.mock.calls.find((call) => call[0].label === 'Operations');
        expect(operationsSection).toBeDefined();
        expect(operationsSection?.[0].items.map((item: NavItem) => item.title)).toEqual(['Assessment', 'Statistics', 'Logs']);
        expect(operationsSection?.[0].items[0].badge).toBe('6.9 / 3.2');
    });

    it('preserves visible zero badges in the curation workspace', () => {
        setMockSharedProps({
            dataResourceCount: 0,
            igsnCount: 0,
        });

        render(<AppSidebar />);

        const sectionCalls = NavSectionMock.mock.calls;
        expect(sectionCalls[1][0].items[1].badge).toBe(0);
        expect(sectionCalls[1][0].items[1].showZeroBadge).toBe(true);
        expect(sectionCalls[2][0].items[0].badge).toBe(0);
        expect(sectionCalls[2][0].items[0].showZeroBadge).toBe(true);
    });

    it('shows Landing Pages inside the administration configuration section when permitted', () => {
        mockUrl = '/landing-pages';
        setMockUser({ can_manage_landing_page_templates: true });

        render(<AppSidebar />);

        const configurationSection = NavSectionMock.mock.calls.find((call) => call[0].label === 'Configuration');
        expect(configurationSection).toBeDefined();
        expect(configurationSection?.[0].items.map((item: NavItem) => item.title)).toEqual(['Editor Settings', 'Landing Pages']);
    });

    it('restores the stored administration workspace on global routes without rendering an open-page section', () => {
        mockUrl = '/docs';
        window.localStorage.setItem(SIDEBAR_WORKSPACE_STORAGE_KEY, 'administration');

        render(<AppSidebar />);

        expect(screen.getByTestId('workspace-switcher')).toHaveAttribute('data-value', 'administration');
        expect(screen.queryByText('Open Page')).not.toBeInTheDocument();
        expect(NavSectionMock.mock.calls.map((call) => call[0].label)).toEqual(['Team', 'Configuration', 'Operations', 'Legacy']);
    });

    it('shows an open-page section when an admin manually switches away from the current workspace', () => {
        render(<AppSidebar />);

        fireEvent.click(screen.getByRole('button', { name: /switch to administration/i }));

        expect(screen.getByTestId('workspace-switcher')).toHaveAttribute('data-value', 'administration');
        expect(window.localStorage.getItem(SIDEBAR_WORKSPACE_STORAGE_KEY)).toBe('administration');
        expect(screen.getByText('Open Page')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /dashboard/i })).toHaveAttribute('href', '/dashboard');
        expect(screen.getByRole('link', { name: /^users$/i })).toHaveAttribute('href', '/users');
    });

    it('falls back to zero badges for assistance when shared counts are missing', () => {
        mockUrl = '/assistance';
        setMockUser({
            role: 'group_leader',
            can_access_logs: false,
            can_access_old_datasets: false,
            can_access_statistics: false,
            can_access_users: false,
            can_access_editor_settings: false,
            can_manage_landing_page_templates: false,
            can_access_assistance: true,
        });
        setMockSharedProps({
            dataResourceCount: undefined,
            igsnCount: undefined,
            pendingAssistanceTotalCount: undefined,
        });

        render(<AppSidebar />);

        const operationsSection = NavSectionMock.mock.calls.find((call) => call[0].label === 'Operations');
        expect(operationsSection).toBeDefined();
        expect(operationsSection?.[0].items[0].badge).toBe(0);
    });

    it('appends tools and administration sections for roles without the workspace switcher', () => {
        setMockUser({
            role: 'curator',
            can_manage_users: false,
            can_access_users: false,
            can_access_old_datasets: false,
            can_access_statistics: false,
            can_access_assistance: true,
            can_access_assessment: true,
            can_access_logs: true,
            can_access_editor_settings: true,
            can_manage_landing_page_templates: true,
        });
        setMockSharedProps({
            assessmentAverageSummary: {
                resources: 8.2,
                igsns: 4.6,
                formatted: '8.2 / 4.6',
            },
        });

        render(<AppSidebar />);

        expect(screen.queryByTestId('workspace-switcher')).not.toBeInTheDocument();
        expect(NavSectionMock.mock.calls.map((call) => call[0].label)).toEqual([
            undefined,
            'Data Curation',
            'IGSN Curation',
            'Tools',
            'Administration',
        ]);

        const toolsSection = NavSectionMock.mock.calls.find((call) => call[0].label === 'Tools');
        const administrationSection = NavSectionMock.mock.calls.find((call) => call[0].label === 'Administration');
        const dataCurationSection = NavSectionMock.mock.calls.find((call) => call[0].label === 'Data Curation');

        expect(dataCurationSection?.[0].items.map((item: NavItem) => item.title)).toEqual(['Data Editor', 'Resources', 'Portal']);
        expect(toolsSection?.[0].items.map((item: NavItem) => item.title)).toEqual(['Assistance', 'Assessment']);
        expect(administrationSection?.[0].items.map((item: NavItem) => item.title)).toEqual(['Logs', 'Editor Settings', 'Landing Pages']);
    });

    it('hides the workspace switcher when privileged users have no administration destinations', () => {
        setMockUser({
            role: 'admin',
            can_access_logs: false,
            can_access_old_datasets: false,
            can_access_statistics: false,
            can_access_users: false,
            can_access_editor_settings: false,
            can_manage_landing_page_templates: false,
            can_access_assistance: false,
            can_access_assessment: false,
        });

        render(<AppSidebar />);

        expect(screen.queryByTestId('workspace-switcher')).not.toBeInTheDocument();
        expect(NavSectionMock.mock.calls.map((call) => call[0].label)).toEqual([undefined, 'Data Curation', 'IGSN Curation']);
    });

    it('does not render the workspace switcher for beginner users', () => {
        setMockUser({
            role: 'beginner',
            can_manage_users: false,
            can_access_logs: false,
            can_access_old_datasets: false,
            can_access_statistics: false,
            can_access_users: false,
            can_access_editor_settings: false,
            can_manage_landing_page_templates: false,
            can_access_assistance: false,
            can_access_assessment: false,
        });

        render(<AppSidebar />);

        expect(screen.queryByTestId('workspace-switcher')).not.toBeInTheDocument();
        expect(NavSectionMock).toHaveBeenCalledTimes(3);
        expect(NavSectionMock.mock.calls.map((call) => call[0].label)).toEqual([undefined, 'Data Curation', 'IGSN Curation']);
    });

    it('does not render the workspace switcher for curator users', () => {
        setMockUser({
            role: 'curator',
            can_manage_users: false,
            can_access_logs: false,
            can_access_old_datasets: false,
            can_access_statistics: false,
            can_access_users: false,
            can_access_editor_settings: false,
            can_manage_landing_page_templates: false,
            can_access_assistance: false,
            can_access_assessment: false,
        });

        render(<AppSidebar />);

        expect(screen.queryByTestId('workspace-switcher')).not.toBeInTheDocument();
        expect(NavSectionMock).toHaveBeenCalledTimes(3);
        expect(NavSectionMock.mock.calls.map((call) => call[0].label)).toEqual([undefined, 'Data Curation', 'IGSN Curation']);
    });
});

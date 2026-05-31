import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    BookOpen,
    ClipboardCheck,
    ClipboardEdit,
    Database,
    FileText,
    FlaskConical,
    History,
    Layers,
    LayoutGrid,
    LayoutTemplate,
    MapPin,
    ScrollText,
    Search,
    Settings,
    Sparkles,
    Users,
} from 'lucide-react';

import { AppSidebarWorkspaceSwitcher } from '@/components/app-sidebar-workspace-switcher';
import { NavFooter } from '@/components/nav-footer';
import { NavSection } from '@/components/nav-section';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useEditorPrefetch } from '@/hooks/use-editor-prefetch';
import {
    pathMatchesSidebarItem,
    useSidebarWorkspace,
} from '@/hooks/use-sidebar-workspace';
import { dashboard, settings } from '@/routes';
import { type NavItem, type SharedData, type SidebarWorkspace, type User as AuthUser } from '@/types';

import AppLogo from './app-logo';

interface SidebarSection {
    items: NavItem[];
    label?: string;
    showSeparator?: boolean;
}

function getNavHref(item: NavItem): string {
    return typeof item.href === 'string' ? item.href : item.href.url;
}

function filterSections(sections: SidebarSection[]): SidebarSection[] {
    return sections.filter((section) => section.items.length > 0);
}

function findMatchingNavItem(sections: SidebarSection[], currentPath: string): NavItem | null {
    const matchedSection = sections.find((section) =>
        section.items.some((item) => pathMatchesCurrentLocation(currentPath, getNavHref(item))),
    );

    if (!matchedSection) {
        return null;
    }

    return matchedSection.items.find((item) => pathMatchesCurrentLocation(currentPath, getNavHref(item))) ?? null;
}

function pathMatchesCurrentLocation(currentPath: string, href: string): boolean {
    return pathMatchesSidebarItem(currentPath, href);
}

export function AppSidebar() {
    const page = usePage<{ auth: { user: AuthUser } } & SharedData>();
    const { auth, dataResourceCount, igsnCount, pendingAssistanceTotalCount, assessmentAverageSummary } = page.props;
    const prefetchEditor = useEditorPrefetch();
    const currentPath = page.url ?? '';
    const isWorkspaceSwitcherEnabled = auth.user?.role === 'admin' || auth.user?.role === 'group_leader';

    // Dashboard - always visible
    const dashboardItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
            tourId: 'sidebar-dashboard',
        },
    ];

    // DATA CURATION section
    const dataCurationItems: NavItem[] = [
        {
            title: 'Data Editor',
            href: '/editor',
            icon: FileText,
            onPrefetch: prefetchEditor,
            tourId: 'sidebar-data-editor',
        },
        {
            title: 'Resources',
            href: '/resources',
            icon: Layers,
            badge: dataResourceCount ?? 0,
            showZeroBadge: true,
            badgeTone: 'primary',
            tourId: 'sidebar-resources',
        },
        {
            title: 'Portal',
            href: '/portal',
            icon: Search,
            openInNewTab: true,
            rel: 'noopener noreferrer',
            tourId: 'sidebar-portal',
        },
    ];

    // IGSN CURATION section
    const igsnCurationItems: NavItem[] = [
        {
            title: 'IGSNs List',
            href: '/igsns',
            icon: FlaskConical,
            badge: igsnCount ?? 0,
            showZeroBadge: true,
            badgeTone: 'primary',
            tourId: 'sidebar-igsns-list',
        },
        {
            title: 'IGSNs Map',
            href: '/igsns-map',
            icon: MapPin,
            tourId: 'sidebar-igsns-map',
        },
        {
            title: 'IGSN Editor',
            href: '/igsn-editor',
            icon: ClipboardEdit,
            disabled: true,
        },
    ];

    const teamItems: NavItem[] = [];
    const configurationItems: NavItem[] = [];
    const operationsItems: NavItem[] = [];
    const legacyItems: NavItem[] = [];
    const administrationItems: NavItem[] = [];
    const toolsItems: NavItem[] = [];

    if (auth.user?.can_access_assistance) {
        const assistanceItem: NavItem = {
            title: 'Assistance',
            href: '/assistance',
            icon: Sparkles,
            badge: pendingAssistanceTotalCount ?? 0,
            badgeTone: (pendingAssistanceTotalCount ?? 0) > 0 ? 'warning' : 'default',
        };

        toolsItems.push(assistanceItem);
        operationsItems.push(assistanceItem);
    }

    if (auth.user?.can_access_assessment) {
        const assessmentItem: NavItem = {
            title: 'Assessment',
            href: '/assessment',
            icon: ClipboardCheck,
            badge: assessmentAverageSummary?.formatted ?? undefined,
        };

        toolsItems.push(assessmentItem);
        operationsItems.push(assessmentItem);
    }

    if (auth.user?.can_access_old_datasets) {
        const oldDatasetsItem: NavItem = {
            title: 'Old Datasets',
            href: '/old-datasets',
            icon: Database,
        };

        administrationItems.push(oldDatasetsItem);
        legacyItems.push(oldDatasetsItem);
    }

    if (auth.user?.can_access_statistics) {
        const statisticsItem: NavItem = {
            title: 'Statistics',
            href: '/statistics',
            icon: BarChart3,
        };

        const legacyStatisticsItem: NavItem = {
            title: 'Statistics (old)',
            href: '/old-statistics',
            icon: History,
        };

        administrationItems.push(statisticsItem);
        operationsItems.push(statisticsItem);
        legacyItems.push(legacyStatisticsItem);
    }

    if (auth.user?.can_access_users) {
        const usersItem: NavItem = {
            title: 'Users',
            href: '/users',
            icon: Users,
        };

        administrationItems.push(usersItem);
        teamItems.push(usersItem);
    }

    if (auth.user?.can_access_logs) {
        const logsItem: NavItem = {
            title: 'Logs',
            href: '/logs',
            icon: ScrollText,
        };

        administrationItems.push(logsItem);
        operationsItems.push(logsItem);
    }

    if (auth.user?.can_access_editor_settings) {
        const editorSettingsItem: NavItem = {
            title: 'Editor Settings',
            href: settings(),
            icon: Settings,
        };

        administrationItems.push(editorSettingsItem);
        configurationItems.push(editorSettingsItem);
    }

    if (auth.user?.can_manage_landing_page_templates) {
        const landingPagesItem: NavItem = {
            title: 'Landing Pages',
            href: '/landing-pages',
            icon: LayoutTemplate,
        };

        administrationItems.push(landingPagesItem);
        configurationItems.push(landingPagesItem);
    }

    const curationSections = filterSections([
        { items: dashboardItems },
        { label: 'Data Curation', items: dataCurationItems },
        { label: 'IGSN Curation', items: igsnCurationItems },
    ]);

    const administrationSections = filterSections([
        { label: 'Team', items: teamItems },
        { label: 'Configuration', items: configurationItems },
        { label: 'Operations', items: operationsItems },
        { label: 'Legacy', items: legacyItems },
    ]);

    const workspacePaths = {
        administration: administrationSections.flatMap((section) => section.items.map((item) => getNavHref(item))),
        curation: curationSections.flatMap((section) => section.items.map((item) => getNavHref(item))),
    };

    const { workspace, setWorkspace, currentPageWorkspace, isCurrentPageOutsideWorkspace } = useSidebarWorkspace({
        currentPath,
        enabled: isWorkspaceSwitcherEnabled && administrationSections.length > 0,
        workspacePaths,
    });

    const currentPageItem = findMatchingNavItem([...curationSections, ...administrationSections], currentPath);

    const currentPageSections: SidebarSection[] =
        isCurrentPageOutsideWorkspace && currentPageWorkspace !== null && currentPageItem !== null
            ? [{ label: 'Open Page', items: [currentPageItem] }]
            : [];

    const visibleWorkspaceSections = isWorkspaceSwitcherEnabled
        ? workspace === 'administration'
            ? administrationSections
            : curationSections
        : [
              ...curationSections,
              ...(toolsItems.length > 0 ? [{ label: 'Tools', items: toolsItems, showSeparator: true }] : []),
              ...(administrationItems.length > 0 ? [{ label: 'Administration', items: administrationItems, showSeparator: true }] : []),
          ];

    const renderedSections = isWorkspaceSwitcherEnabled
        ? [...currentPageSections, ...visibleWorkspaceSections]
        : visibleWorkspaceSections;

    // Footer navigation contains only informational links.
    const footerNavItems: NavItem[] = [];

    footerNavItems.push(
        {
            title: 'Changelog',
            href: '/changelog',
            icon: History,
        },
        {
            title: 'Documentation',
            href: '/docs',
            icon: BookOpen,
            tourId: 'sidebar-documentation',
        },
    );

    return (
        <Sidebar collapsible="icon" variant="inset" data-tour="sidebar-root">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard().url} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {isWorkspaceSwitcherEnabled && administrationSections.length > 0 && (
                    <AppSidebarWorkspaceSwitcher value={workspace as SidebarWorkspace} onValueChange={setWorkspace} />
                )}
            </SidebarHeader>

            <SidebarContent>
                {renderedSections.map((section, index) => (
                    <NavSection
                        key={`${section.label ?? 'section'}-${index}`}
                        items={section.items}
                        label={section.label}
                        showSeparator={section.showSeparator ?? index > 0}
                    />
                ))}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

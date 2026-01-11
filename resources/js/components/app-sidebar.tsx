import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    BookOpen,
    ClipboardEdit,
    Database,
    FileText,
    FlaskConical,
    History,
    Layers,
    LayoutGrid,
    ScrollText,
    Settings,
    Users,
} from 'lucide-react';

import { NavFooter } from '@/components/nav-footer';
import { NavSection } from '@/components/nav-section';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { dashboard, settings } from '@/routes';
import { type NavItem, type User as AuthUser } from '@/types';

import AppLogo from './app-logo';

export function AppSidebar() {
    const { auth } = usePage<{ auth: { user: AuthUser } }>().props;

    // Dashboard - always visible
    const dashboardItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    // DATA CURATION section
    const dataCurationItems: NavItem[] = [
        {
            title: 'Data Editor',
            href: '/editor',
            icon: FileText,
        },
        {
            title: 'Resources',
            href: '/resources',
            icon: Layers,
        },
    ];

    // IGSN CURATION section
    const igsnCurationItems: NavItem[] = [
        {
            title: 'IGSNs',
            href: '/igsns',
            icon: FlaskConical,
            disabled: true,
        },
        {
            title: 'IGSN Editor',
            href: '/igsn-editor',
            icon: ClipboardEdit,
            disabled: true,
        },
    ];

    // ADMINISTRATION section - dynamically built based on granular permissions (Issue #379)
    const administrationItems: NavItem[] = [];

    if (auth.user?.can_access_old_datasets) {
        administrationItems.push({
            title: 'Old Datasets',
            href: '/old-datasets',
            icon: Database,
        });
    }

    if (auth.user?.can_access_statistics) {
        administrationItems.push({
            title: 'Statistics (old)',
            href: '/old-statistics',
            icon: BarChart3,
        });
    }

    if (auth.user?.can_access_users) {
        administrationItems.push({
            title: 'Users',
            href: '/users',
            icon: Users,
        });
    }

    if (auth.user?.can_access_logs) {
        administrationItems.push({
            title: 'Logs',
            href: '/logs',
            icon: ScrollText,
        });
    }

    // Footer navigation - Editor Settings only for users with permission (Issue #379)
    const footerNavItems: NavItem[] = [];

    if (auth.user?.can_access_editor_settings) {
        footerNavItems.push({
            title: 'Editor Settings',
            href: settings(),
            icon: Settings,
        });
    }

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
        },
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
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
            </SidebarHeader>

            <SidebarContent>
                <NavSection items={dashboardItems} />
                <NavSection label="Data Curation" items={dataCurationItems} showSeparator />
                <NavSection label="IGSN Curation" items={igsnCurationItems} showSeparator />
                {administrationItems.length > 0 && <NavSection label="Administration" items={administrationItems} showSeparator />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

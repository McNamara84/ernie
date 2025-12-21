import { Link, usePage } from '@inertiajs/react';
import { BarChart3, BookOpen, ClipboardEdit, Database, FileText, FlaskConical, History, Layers, LayoutGrid, Settings, Users } from 'lucide-react';

import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { withBasePath } from '@/lib/base-path';
import { dashboard, settings } from '@/routes';
import { type User as AuthUser, type NavItem } from '@/types';

import AppLogo from './app-logo';

export function AppSidebar() {
    const { auth } = usePage<{ auth: { user: AuthUser } }>().props;

    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Data Editor',
            href: withBasePath('/editor'),
            icon: FileText,
            separator: true,
        },
        {
            title: 'Old Datasets',
            href: withBasePath('/old-datasets'),
            icon: Database,
        },
        {
            title: 'Statistics (old)',
            href: withBasePath('/old-statistics'),
            icon: BarChart3,
        },
        {
            title: 'Resources',
            href: withBasePath('/resources'),
            icon: Layers,
        },
        {
            title: 'IGSNs',
            href: withBasePath('/igsns'),
            icon: FlaskConical,
            disabled: true,
            separator: true,
        },
        {
            title: 'IGSN Editor',
            href: withBasePath('/igsn-editor'),
            icon: ClipboardEdit,
            disabled: true,
        },
    ];

    const footerNavItems: NavItem[] = [
        // Users link - only visible for admins and group leaders
        ...(auth.user?.can_manage_users
            ? [
                  {
                      title: 'Users',
                      href: withBasePath('/users'),
                      icon: Users,
                  } as NavItem,
              ]
            : []),
        {
            title: 'Editor Settings',
            href: settings(),
            icon: Settings,
        },
        {
            title: 'Changelog',
            href: withBasePath('/changelog'),
            icon: History,
        },
        {
            title: 'Documentation',
            href: withBasePath('/docs'),
            icon: BookOpen,
        },
    ];

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
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

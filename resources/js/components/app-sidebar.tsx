import { Link } from '@inertiajs/react';
import { BookOpen, ClipboardEdit, Database, FileText, FlaskConical, History, Layers,LayoutGrid, Settings } from 'lucide-react';

import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { withBasePath } from '@/lib/base-path';
import { dashboard, settings } from '@/routes';
import { type NavItem } from '@/types';

import AppLogo from './app-logo';

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

export function AppSidebar() {
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

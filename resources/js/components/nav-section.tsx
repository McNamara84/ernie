import { Link, usePage } from '@inertiajs/react';

import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarSeparator } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';

interface NavSectionProps {
    label?: string;
    items: NavItem[];
    showSeparator?: boolean;
}

export function NavSection({ label, items, showSeparator = false }: NavSectionProps) {
    const page = usePage();

    if (items.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            {showSeparator && <SidebarSeparator className="my-2" />}
            {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
            <SidebarMenu>
                {items.map((item) => {
                    const href = typeof item.href === 'string' ? item.href : item.href.url;
                    return (
                        <SidebarMenuItem key={item.title}>
                            {item.disabled ? (
                                <SidebarMenuButton disabled tooltip={{ children: item.title }} className="cursor-not-allowed opacity-50">
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </SidebarMenuButton>
                            ) : (
                                // Using startsWith for isActive to highlight parent navigation items
                                // when viewing child routes (e.g., /users is active when on /users/create)
                                <SidebarMenuButton asChild isActive={page.url.startsWith(href)} tooltip={{ children: item.title }}>
                                    <Link href={href} prefetch>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            )}
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}

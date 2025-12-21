import { Link, usePage } from '@inertiajs/react';
import { Fragment } from 'react';

import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarSeparator } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const page = usePage();
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Main Menu</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item, index) => {
                    const href = typeof item.href === 'string' ? item.href : item.href.url;
                    return (
                        <Fragment key={item.title}>
                            {item.separator && index > 0 && <SidebarSeparator className="my-2" />}
                            <SidebarMenuItem>
                                {item.disabled ? (
                                    <SidebarMenuButton disabled tooltip={{ children: item.title }} className="cursor-not-allowed opacity-50">
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </SidebarMenuButton>
                                ) : (
                                    <SidebarMenuButton asChild isActive={page.url.startsWith(href)} tooltip={{ children: item.title }}>
                                        <Link href={href} prefetch>
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                )}
                            </SidebarMenuItem>
                        </Fragment>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}

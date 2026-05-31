import { Link } from '@inertiajs/react';
import { type ComponentPropsWithoutRef } from 'react';

import { Icon } from '@/components/icon';
import { SidebarGroup, SidebarGroupContent, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { buildExternalLinkRel } from '@/lib/external-link-rel';
import { type NavItem } from '@/types';

export function NavFooter({
    items,
    className,
    ...props
}: ComponentPropsWithoutRef<typeof SidebarGroup> & {
    items: NavItem[];
}) {
    return (
        <SidebarGroup {...props} className={`group-data-[collapsible=icon]:p-0 ${className || ''}`}>
            <SidebarGroupContent>
                <SidebarMenu>
                    {items.map((item) => {
                        const href = typeof item.href === 'string' ? item.href : item.href.url;

                        const linkContent = (
                            <>
                                {item.icon && <Icon iconNode={item.icon} className="h-5 w-5" />}
                                <span>{item.title}</span>
                            </>
                        );

                        return (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    asChild
                                    className="text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                                >
                                    {item.openInNewTab ? (
                                        <a href={href} target="_blank" rel={buildExternalLinkRel(item.rel)} data-tour={item.tourId}>
                                            {linkContent}
                                        </a>
                                    ) : (
                                        <Link href={href} prefetch data-tour={item.tourId}>
                                            {linkContent}
                                        </Link>
                                    )}
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}

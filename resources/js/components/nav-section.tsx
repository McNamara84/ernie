import { Link, usePage } from '@inertiajs/react';

import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem, SidebarSeparator } from '@/components/ui/sidebar';
import { buildExternalLinkRel } from '@/lib/external-link-rel';
import { cn } from '@/lib/utils';
import { type NavItem } from '@/types';

interface NavSectionProps {
    label?: string;
    items: NavItem[];
    showSeparator?: boolean;
}

export function NavSection({ label, items, showSeparator = false }: NavSectionProps) {
    const page = usePage();

    const badgeToneClasses: Record<NonNullable<NavItem['badgeTone']>, string> = {
        default: 'bg-sidebar-accent/70 text-sidebar-accent-foreground',
        primary:
            'bg-gfz-primary text-gfz-primary-foreground peer-hover/menu-button:text-gfz-primary-foreground peer-data-[active=true]/menu-button:text-gfz-primary-foreground',
        warning: 'bg-amber-500/15 text-amber-700 dark:text-amber-200',
    };

    const shouldRenderBadge = (item: NavItem) => {
        if (item.badge === undefined) {
            return false;
        }

        if (typeof item.badge === 'string') {
            return item.badge.trim().length > 0;
        }

        return item.badge > 0 || (item.showZeroBadge === true && item.badge === 0);
    };

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
                    const isActive = !item.openInNewTab && (
                        page.url === href ||
                        page.url.startsWith(href + '/') ||
                        page.url.startsWith(href + '?') ||
                        page.url.startsWith(href + '#')
                    );

                    const linkContent = (
                        <>
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                        </>
                    );

                    return (
                        <SidebarMenuItem key={item.title}>
                            {item.disabled ? (
                                <SidebarMenuButton disabled tooltip={{ children: item.title }} className="cursor-not-allowed opacity-50" data-tour={item.tourId}>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </SidebarMenuButton>
                            ) : (
                                // Check if current URL starts with href and is followed by '/', '?', '#', or end of string
                                // This prevents '/user' from matching when on '/users' (path boundary check)
                                <SidebarMenuButton
                                    asChild
                                    isActive={isActive}
                                    tooltip={{ children: item.title }}
                                >
                                    {item.openInNewTab ? (
                                        <a
                                            href={href}
                                            target="_blank"
                                            rel={buildExternalLinkRel(item.rel)}
                                            onMouseEnter={item.onPrefetch}
                                            onFocus={item.onPrefetch}
                                            data-tour={item.tourId}
                                        >
                                            {linkContent}
                                        </a>
                                    ) : (
                                        <Link href={href} prefetch onMouseEnter={item.onPrefetch} onFocus={item.onPrefetch} data-tour={item.tourId} aria-current={isActive ? 'page' : undefined}>
                                            {linkContent}
                                        </Link>
                                    )}
                                </SidebarMenuButton>
                            )}
                            {shouldRenderBadge(item) && (
                                <SidebarMenuBadge
                                    className={cn(
                                        'right-2 rounded-full px-1.5',
                                        typeof item.badge === 'string' && 'max-w-[5.75rem] truncate rounded-md px-2 text-[11px]',
                                        badgeToneClasses[item.badgeTone ?? 'default'],
                                    )}
                                >
                                    {item.badge}
                                </SidebarMenuBadge>
                            )}
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}

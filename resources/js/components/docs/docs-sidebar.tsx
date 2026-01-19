import { ChevronRight } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { DocsSidebarItem } from '@/types/docs';

interface DocsSidebarProps {
    /** Navigation items to display */
    items: DocsSidebarItem[];
    /** Currently active section ID */
    activeId: string | null;
    /** Callback when a section is clicked */
    onSectionClick: (id: string) => void;
    /** Additional CSS classes */
    className?: string;
}

/**
 * Sticky sidebar for documentation navigation with scroll-spy highlighting.
 * Supports nested navigation items and smooth scrolling.
 */
export function DocsSidebar({ items, activeId, onSectionClick, className }: DocsSidebarProps) {
    return (
        <nav
            className={cn('sticky top-20 hidden h-[calc(100vh-6rem)] w-64 shrink-0 overflow-y-auto lg:block', className)}
            aria-label="Documentation navigation"
        >
            <div className="space-y-1 pr-4">
                {items.map((item) => (
                    <SidebarItem key={item.id} item={item} activeId={activeId} onSectionClick={onSectionClick} depth={0} />
                ))}
            </div>
        </nav>
    );
}

interface SidebarItemProps {
    item: DocsSidebarItem;
    activeId: string | null;
    onSectionClick: (id: string) => void;
    depth: number;
}

function SidebarItem({ item, activeId, onSectionClick, depth }: SidebarItemProps) {
    const isActive = activeId === item.id;
    const hasChildren = item.children && item.children.length > 0;
    const hasActiveChild = hasChildren && item.children?.some((child) => child.id === activeId || child.children?.some((c) => c.id === activeId));

    const handleClick = (e: React.MouseEvent) => {
        e.preventDefault();
        onSectionClick(item.id);
    };

    return (
        <div>
            <button
                onClick={handleClick}
                className={cn(
                    'group flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    'hover:bg-accent hover:text-accent-foreground',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                    isActive && 'bg-accent text-accent-foreground',
                    !isActive && !hasActiveChild && 'text-muted-foreground',
                    hasActiveChild && !isActive && 'text-foreground',
                    depth > 0 && 'ml-4',
                )}
                aria-current={isActive ? 'location' : undefined}
            >
                {item.icon && <item.icon className={cn('size-4 shrink-0', isActive ? 'text-primary' : 'text-muted-foreground')} />}
                <span className="truncate">{item.label}</span>
                {isActive && <ChevronRight className="ml-auto size-4 text-primary" />}
            </button>
            {hasChildren && (
                <div className="mt-1 space-y-1">
                    {item.children?.map((child) => (
                        <SidebarItem key={child.id} item={child} activeId={activeId} onSectionClick={onSectionClick} depth={depth + 1} />
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * Mobile sidebar trigger - shows on smaller screens
 */
interface DocsSidebarMobileProps {
    items: DocsSidebarItem[];
    activeId: string | null;
    onSectionClick: (id: string) => void;
}

export function DocsSidebarMobile({ items, activeId, onSectionClick }: DocsSidebarMobileProps) {
    return (
        <div className="mb-6 lg:hidden">
            <div className="rounded-lg border bg-card p-4">
                <h2 className="mb-3 text-sm font-semibold text-muted-foreground uppercase tracking-wide">On this page</h2>
                <nav className="flex flex-wrap gap-2">
                    {items.map((item) => (
                        <button
                            key={item.id}
                            onClick={() => onSectionClick(item.id)}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm transition-colors',
                                'hover:bg-accent hover:text-accent-foreground',
                                activeId === item.id ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {item.icon && <item.icon className="size-3.5" />}
                            {item.label}
                        </button>
                    ))}
                </nav>
            </div>
        </div>
    );
}

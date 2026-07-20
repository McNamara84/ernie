import { Breadcrumbs } from '@/components/breadcrumbs';
import { FontSizeQuickToggle } from '@/components/font-size-quick-toggle';
import { Badge } from '@/components/ui/badge';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useNavigationStatus } from '@/hooks/use-navigation-status';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const currentContext = breadcrumbs.at(-1)?.title ?? 'Workspace';
    const { isNavigating, statusText } = useNavigationStatus(currentContext);

    return (
        <header
            data-slot="app-sidebar-header"
            className="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 bg-background px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:static md:z-auto md:px-4"
        >
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="ml-auto flex items-center gap-2">
                <div className="hidden items-center gap-2 rounded-full border bg-background/80 px-3 py-1.5 md:flex" aria-live="polite">
                    <span className={`h-2.5 w-2.5 rounded-full ${isNavigating ? 'animate-pulse bg-primary' : 'bg-emerald-500'}`} aria-hidden="true" />
                    <Badge variant={isNavigating ? 'secondary' : 'outline'} data-testid="header-context-badge">
                        {currentContext}
                    </Badge>
                    <span data-testid="navigation-status" className="text-sm text-muted-foreground">
                        {statusText}
                    </span>
                </div>
                <FontSizeQuickToggle />
            </div>
        </header>
    );
}

import { type PropsWithChildren } from 'react';
import { Toaster } from 'sonner';

import { AppContent } from '@/components/app-content';
import { AppFooter } from '@/components/app-footer';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ErrorBoundary } from '@/components/error-boundary';
import { useSessionWarmup } from '@/hooks/use-session-warmup';
import { type BreadcrumbItem } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[] }>) {
    // Ensure session/CSRF token is initialized on first mount
    useSessionWarmup();

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <ErrorBoundary>
                    {children}
                </ErrorBoundary>
                <AppFooter />
            </AppContent>
            <Toaster position="bottom-right" richColors />
        </AppShell>
    );
}

import type { PropsWithChildren } from 'react';

import { AppContent } from '@/components/app-content';
import { AppFooter } from '@/components/app-footer';
import { AppHeader } from '@/components/app-header';
import { AppShell } from '@/components/app-shell';
import { TooltipProvider } from '@/components/ui/tooltip';
import { type BreadcrumbItem } from '@/types';

export default function AppHeaderLayout({ children, breadcrumbs }: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[] }>) {
    return (
        <TooltipProvider delayDuration={0}>
            <AppShell>
                <AppHeader breadcrumbs={breadcrumbs} />
                <AppContent>{children}</AppContent>
                <AppFooter />
            </AppShell>
        </TooltipProvider>
    );
}

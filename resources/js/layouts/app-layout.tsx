import { type ReactNode } from 'react';

import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    showFooter?: boolean;
    transitionKey?: string;
}

export default ({ children, breadcrumbs, showFooter, transitionKey }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} showFooter={showFooter} transitionKey={transitionKey}>
        {children}
    </AppLayoutTemplate>
);

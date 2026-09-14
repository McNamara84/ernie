import { type ReactNode } from 'react';

import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    showFooter?: boolean;
}

export default ({ children, breadcrumbs, showFooter }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} showFooter={showFooter}>
        {children}
    </AppLayoutTemplate>
);

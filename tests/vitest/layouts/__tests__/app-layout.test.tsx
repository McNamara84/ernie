import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types';
import { describe, expect, it, vi } from 'vitest';
import AppLayout from '@/layouts/app-layout';

const AppLayoutTemplateMock = vi.hoisted(() =>
    vi.fn(
        ({ children, breadcrumbs }: { children: ReactNode; breadcrumbs?: BreadcrumbItem[] }) => (
            <div>
                {breadcrumbs && (
                    <nav data-testid="breadcrumbs">
                        {breadcrumbs.map((b: BreadcrumbItem) => b.title).join(',')}
                    </nav>
                )}
                <div data-testid="content">{children}</div>
            </div>
        ),
    ),
);

vi.mock('@/layouts/app/app-sidebar-layout', () => ({
    default: AppLayoutTemplateMock,
}));

describe('AppLayout', () => {
    it('passes breadcrumbs and renders children', () => {
        const breadcrumbs = [{ title: 'Home', href: '/' }];
        render(
            <AppLayout breadcrumbs={breadcrumbs}>
                <p>Dashboard</p>
            </AppLayout>,
        );
        expect(screen.getByTestId('content')).toHaveTextContent('Dashboard');
        expect(screen.getByTestId('breadcrumbs')).toHaveTextContent('Home');
        expect(AppLayoutTemplateMock).toHaveBeenCalled();
        const callProps = AppLayoutTemplateMock.mock.calls[0][0];
        expect(callProps.breadcrumbs).toEqual(breadcrumbs);
    });
});


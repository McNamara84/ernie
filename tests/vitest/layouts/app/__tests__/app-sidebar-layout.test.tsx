import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';

const AppShellMock = vi.hoisted(() =>
    vi.fn(({ children, variant }: { children?: React.ReactNode; variant?: string }) => (
        <div data-testid="app-shell" data-variant={variant}>{children}</div>
    ))
);
const AppSidebarMock = vi.hoisted(() => vi.fn(() => <div data-testid="app-sidebar" />));
const AppContentMock = vi.hoisted(() =>
    vi.fn(({ children, variant }: { children?: React.ReactNode; variant?: string }) => (
        <main data-testid="app-content" data-variant={variant}>{children}</main>
    ))
);
const AppSidebarHeaderMock = vi.hoisted(() =>
    vi.fn(({ breadcrumbs }: { breadcrumbs?: { title: string }[] }) => (
        <header data-testid="app-sidebar-header">{breadcrumbs?.map((b) => b.title).join(',')}</header>
    ))
);

vi.mock('@/components/app-shell', () => ({ AppShell: AppShellMock }));
vi.mock('@/components/app-sidebar', () => ({ AppSidebar: AppSidebarMock }));
vi.mock('@/components/app-content', () => ({ AppContent: AppContentMock }));
vi.mock('@/components/app-sidebar-header', () => ({ AppSidebarHeader: AppSidebarHeaderMock }));

describe('AppSidebarLayout', () => {
    it('renders sidebar layout with breadcrumbs and children', () => {
        render(
            <AppSidebarLayout breadcrumbs={[{ title: 'Settings', href: '/settings' }]}>Child</AppSidebarLayout>
        );

        const shellArgs = AppShellMock.mock.calls[0][0];
        expect(shellArgs.variant).toBe('sidebar');
        expect(AppSidebarMock).toHaveBeenCalled();
        const content = screen.getByTestId('app-content');
        expect(content).toHaveAttribute('data-variant', 'sidebar');
        expect(screen.getByTestId('app-sidebar-header')).toHaveTextContent('Settings');
        expect(screen.getByText('Child')).toBeInTheDocument();
    });
});


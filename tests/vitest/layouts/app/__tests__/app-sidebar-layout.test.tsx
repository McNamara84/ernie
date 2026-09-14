import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { useFeedbackDiagnostics } from '@/hooks/use-feedback-diagnostics';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';

const AppShellMock = vi.hoisted(() =>
    vi.fn(({ children, variant }: { children?: React.ReactNode; variant?: string }) => (
        <div data-testid="app-shell" data-variant={variant}>
            {children}
        </div>
    )),
);
const AppSidebarMock = vi.hoisted(() => vi.fn(() => <div data-testid="app-sidebar" />));
const AppFooterMock = vi.hoisted(() => vi.fn(() => <footer data-testid="app-footer" />));
const AppContentMock = vi.hoisted(() =>
    vi.fn(({ children, variant, className }: { children?: React.ReactNode; variant?: string; className?: string }) => (
        <main data-testid="app-content" data-variant={variant} className={className}>
            {children}
        </main>
    )),
);
const AppSidebarHeaderMock = vi.hoisted(() =>
    vi.fn(({ breadcrumbs }: { breadcrumbs?: { title: string }[] }) => (
        <header data-testid="app-sidebar-header">{breadcrumbs?.map((b) => b.title).join(',')}</header>
    )),
);

vi.mock('@/components/app-shell', () => ({ AppShell: AppShellMock }));
vi.mock('@/components/app-sidebar', () => ({ AppSidebar: AppSidebarMock }));
vi.mock('@/components/app-footer', () => ({ AppFooter: AppFooterMock }));
vi.mock('@/components/app-content', () => ({ AppContent: AppContentMock }));
vi.mock('@/components/app-sidebar-header', () => ({ AppSidebarHeader: AppSidebarHeaderMock }));

describe('AppSidebarLayout', () => {
    it('renders sidebar layout with breadcrumbs and children', () => {
        render(<AppSidebarLayout breadcrumbs={[{ title: 'Settings', href: '/settings' }]}>Child</AppSidebarLayout>);

        const shellArgs = AppShellMock.mock.calls[0][0];
        expect(shellArgs.variant).toBe('sidebar');
        expect(AppSidebarMock).toHaveBeenCalled();
        const content = screen.getByTestId('app-content');
        expect(content).toHaveAttribute('data-variant', 'sidebar');
        expect(content).toHaveClass('min-w-0', 'overflow-x-clip');
        expect(content).not.toHaveClass('overflow-x-hidden');
        expect(screen.getByTestId('app-sidebar-header')).toHaveTextContent('Settings');
        expect(screen.getByText('Child')).toBeInTheDocument();
        expect(screen.getByTestId('app-footer')).toBeInTheDocument();
        expect(useFeedbackDiagnostics).toHaveBeenCalledOnce();
    });

    it('omits only the global footer when requested', () => {
        render(
            <AppSidebarLayout breadcrumbs={[{ title: 'Editor', href: '/editor' }]} showFooter={false}>
                Editor content
            </AppSidebarLayout>,
        );

        expect(screen.queryByTestId('app-footer')).not.toBeInTheDocument();
        expect(screen.getByTestId('app-sidebar')).toBeInTheDocument();
        expect(screen.getByTestId('app-sidebar-header')).toHaveTextContent('Editor');
        expect(screen.getByText('Editor content')).toBeInTheDocument();
    });
});

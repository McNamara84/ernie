import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { AppSidebarHeader } from '@/components/app-sidebar-header';

const useNavigationStatusMock = vi.fn();

vi.mock('@/components/breadcrumbs', () => ({
    Breadcrumbs: ({ breadcrumbs }: { breadcrumbs: { title: string }[] }) => (
        <nav data-testid="breadcrumbs">
            {breadcrumbs.map((b) => (
                <span key={b.title}>{b.title}</span>
            ))}
        </nav>
    ),
}));

vi.mock('@/components/ui/sidebar', () => ({
    SidebarTrigger: (props: ComponentProps<'button'>) => (
        <button data-testid="sidebar-trigger" {...props} />
    ),
}));

vi.mock('@/components/font-size-quick-toggle', () => ({
    FontSizeQuickToggle: () => <button data-testid="font-size-toggle">Font Size</button>,
}));

vi.mock('@/hooks/use-navigation-status', () => ({
    useNavigationStatus: (context: string) => useNavigationStatusMock(context),
}));

describe('AppSidebarHeader', () => {
    it('renders current context and ready status', () => {
        useNavigationStatusMock.mockReturnValue({ isNavigating: false, statusText: 'Ready' });

        render(
            <AppSidebarHeader
                breadcrumbs={[
                    { title: 'Home', href: '/' },
                    { title: 'Settings', href: '/settings' },
                ]}
            />,
        );

        expect(useNavigationStatusMock).toHaveBeenCalledWith('Settings');
        expect(screen.getByTestId('header-context-badge')).toHaveTextContent('Settings');
        expect(screen.getByTestId('navigation-status')).toHaveTextContent('Ready');
    });

    it('renders sidebar trigger and breadcrumbs', () => {
        useNavigationStatusMock.mockReturnValue({ isNavigating: false, statusText: 'Ready' });

        render(
            <AppSidebarHeader
                breadcrumbs={[
                    { title: 'Home', href: '/' },
                    { title: 'Settings', href: '/settings' },
                ]}
            />
        );
        expect(screen.getByTestId('sidebar-trigger')).toBeInTheDocument();
        const breadcrumbs = screen.getByTestId('breadcrumbs');
        expect(within(breadcrumbs).getByText('Home')).toBeInTheDocument();
        expect(within(breadcrumbs).getByText('Settings')).toBeInTheDocument();
    });

    it('renders font size quick toggle', () => {
        useNavigationStatusMock.mockReturnValue({ isNavigating: true, statusText: 'Opening Workspace...' });

        render(<AppSidebarHeader breadcrumbs={[]} />);
        expect(screen.getByTestId('font-size-toggle')).toBeInTheDocument();
        expect(screen.getByTestId('header-context-badge')).toHaveTextContent('Workspace');
        expect(screen.getByTestId('navigation-status')).toHaveTextContent('Opening Workspace...');
    });
});

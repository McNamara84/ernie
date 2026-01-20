import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { AppSidebarHeader } from '@/components/app-sidebar-header';

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

describe('AppSidebarHeader', () => {
    it('renders sidebar trigger and breadcrumbs', () => {
        render(
            <AppSidebarHeader
                breadcrumbs={[
                    { title: 'Home', href: '/' },
                    { title: 'Settings', href: '/settings' },
                ]}
            />
        );
        expect(screen.getByTestId('sidebar-trigger')).toBeInTheDocument();
        expect(screen.getByText('Home')).toBeInTheDocument();
        expect(screen.getByText('Settings')).toBeInTheDocument();
    });

    it('renders font size quick toggle', () => {
        render(<AppSidebarHeader breadcrumbs={[]} />);
        expect(screen.getByTestId('font-size-toggle')).toBeInTheDocument();
    });
});

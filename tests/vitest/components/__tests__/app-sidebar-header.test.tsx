import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ComponentProps } from 'react';

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
});


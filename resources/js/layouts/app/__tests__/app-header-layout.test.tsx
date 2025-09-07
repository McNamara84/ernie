import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import AppHeaderLayout from '../app-header-layout';
import type { BreadcrumbItem } from '@/types';

vi.mock('@/components/app-shell', () => ({
    AppShell: ({ children }: { children?: React.ReactNode }) => <div data-testid="shell">{children}</div>,
}));

vi.mock('@/components/app-header', () => ({
    AppHeader: ({ breadcrumbs }: { breadcrumbs?: BreadcrumbItem[] }) => (
        <div data-testid="header">{breadcrumbs?.map((b) => b.title).join(' > ')}</div>
    ),
}));

vi.mock('@/components/app-content', () => ({
    AppContent: ({ children }: { children?: React.ReactNode }) => <main>{children}</main>,
}));

describe('AppHeaderLayout', () => {
    it('renders header with breadcrumbs and content', () => {
        const crumbs: BreadcrumbItem[] = [
            { title: 'Home', href: '#' },
            { title: 'Settings', href: '#' },
        ];
        render(
            <AppHeaderLayout breadcrumbs={crumbs}>
                <div>Body</div>
            </AppHeaderLayout>,
        );
        expect(screen.getByTestId('shell')).toBeInTheDocument();
        expect(screen.getByTestId('header')).toHaveTextContent('Home > Settings');
        expect(screen.getByText('Body')).toBeInTheDocument();
    });
});


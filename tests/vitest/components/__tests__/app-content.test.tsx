import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { AppContent } from '@/components/app-content';

vi.mock('@/components/ui/sidebar', () => ({
    SidebarInset: ({ children, ...props }: { children?: React.ReactNode }) => (
        <aside data-testid="sidebar-inset" {...props}>{children}</aside>
    ),
}));

describe('AppContent', () => {
    it('renders header variant by default', () => {
        render(<AppContent>Content</AppContent>);
        const main = screen.getByRole('main');
        expect(main).toHaveClass(
            'mx-auto',
            'flex',
            'h-full',
            'w-full',
            'max-w-7xl',
            'flex-1',
        );
        expect(main).toHaveTextContent('Content');
    });

    it('renders sidebar variant using SidebarInset', () => {
        render(
            <AppContent variant="sidebar">Sidebar content</AppContent>
        );
        const inset = screen.getByTestId('sidebar-inset');
        expect(inset).toBeInTheDocument();
        expect(inset).toHaveTextContent('Sidebar content');
    });
});


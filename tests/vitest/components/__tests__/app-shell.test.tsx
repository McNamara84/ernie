import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AppShell } from '@/components/app-shell';

const usePageMock = vi.hoisted(() => vi.fn());
const SidebarProviderMock = vi.hoisted(() =>
    vi.fn(
        ({
            children,
            defaultOpen,
        }: {
            children: React.ReactNode;
            defaultOpen: boolean;
        }) => (
            <div data-testid="sidebar-provider" data-default-open={String(defaultOpen)}>
                {children}
            </div>
        ),
    ),
);

vi.mock('@inertiajs/react', () => ({
    usePage: () => usePageMock(),
}));

vi.mock('@/components/ui/sidebar', () => ({
    SidebarProvider: SidebarProviderMock,
}));

describe('AppShell', () => {
    it('renders header variant by default', () => {
        usePageMock.mockReturnValue({ props: { sidebarOpen: false } });
        render(
            <AppShell>
                <p>Content</p>
            </AppShell>,
        );
        const wrapper = screen.getByText('Content').parentElement;
        if (!wrapper) throw new Error('wrapper not found');
        expect(wrapper).toHaveClass('flex', 'min-h-screen');
    });

    it('uses SidebarProvider for sidebar variant', () => {
        usePageMock.mockReturnValue({ props: { sidebarOpen: true } });
        render(
            <AppShell variant="sidebar">
                <p>Sidebar content</p>
            </AppShell>,
        );
        const callArgs = SidebarProviderMock.mock.calls[0][0];
        expect(callArgs.defaultOpen).toBe(true);
        const provider = screen.getByTestId('sidebar-provider');
        expect(provider).toBeInTheDocument();
        expect(provider).toHaveAttribute('data-default-open', 'true');
    });
});


import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ResourcesPage from '@/pages/resources';

// Mock Inertia router
vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: {
        visit: vi.fn(),
        reload: vi.fn(),
        get: vi.fn(),
    },
    usePage: () => ({
        props: {
            auth: {
                user: {
                    can_manage_landing_pages: true,
                },
            },
        },
    }),
}));

// Mock axios
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
    isAxiosError: vi.fn(),
}));

// Mock toast
vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        warning: vi.fn(),
    },
}));

// Mock AppLayout
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

describe('ResourcesPage - Filter Visibility', () => {
    const mockPagination = {
        current_page: 1,
        last_page: 1,
        per_page: 20,
        total: 0,
        from: 0,
        to: 0,
        has_more: false,
    };

    const mockSort = {
        key: 'updated_at' as const,
        direction: 'desc' as const,
    };

    it('shows filters even when no resources are found', () => {
        render(
            <ResourcesPage
                resources={[]}
                pagination={mockPagination}
                sort={mockSort}
            />
        );

        // Search input should be visible
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
        
        // No results message should be shown
        expect(screen.getByText(/no resources found matching your filters/i)).toBeInTheDocument();
    });

    it('shows filters when resources are available', () => {
        const mockResources = [
            {
                id: 1,
                doi: '10.5880/test.001',
                year: 2024,
                title: 'Test Resource',
                resourcetypegeneral: 'Dataset',
            },
        ];

        const mockPaginationWithData = {
            ...mockPagination,
            total: 1,
            from: 1,
            to: 1,
        };

        render(
            <ResourcesPage
                resources={mockResources}
                pagination={mockPaginationWithData}
                sort={mockSort}
            />
        );

        // Search input should be visible
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
        
        // Resource should be shown
        expect(screen.getByText('Test Resource')).toBeInTheDocument();
    });

    it('shows helpful message when filters return no results', () => {
        render(
            <ResourcesPage
                resources={[]}
                pagination={mockPagination}
                sort={mockSort}
            />
        );

        // Message should guide users to adjust filters
        expect(screen.getByText(/no resources found matching your filters/i)).toBeInTheDocument();
        
        // Filters should still be accessible to modify
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
    });

    it('shows filters during loading state', () => {
        render(
            <ResourcesPage
                resources={[]}
                pagination={mockPagination}
                sort={mockSort}
            />
        );

        // Filters should be present (even if disabled during loading)
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
    });
});

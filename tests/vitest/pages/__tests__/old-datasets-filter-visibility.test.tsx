import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import OldDatasetsPage from '@/pages/old-datasets';

// Mock Inertia
vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: {
        visit: vi.fn(),
        reload: vi.fn(),
        get: vi.fn(),
    },
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

describe('OldDatasetsPage - Filter Visibility', () => {
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

    it('shows filters even when no datasets are found', () => {
        render(
            <OldDatasetsPage
                datasets={[]}
                pagination={mockPagination}
                sort={mockSort}
            />
        );

        // Search input should be visible
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
        
        // No results message should be shown
        expect(screen.getByText(/no old datasets found matching your filters/i)).toBeInTheDocument();
    });

    it('shows filters when datasets are available', () => {
        const mockDatasets = [
            {
                id: 1,
                identifier: '10.5880/old.001',
                publicationyear: 2023,
                title: 'Old Test Dataset',
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
            <OldDatasetsPage
                datasets={mockDatasets}
                pagination={mockPaginationWithData}
                sort={mockSort}
            />
        );

        // Search input should be visible
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
        
        // Dataset should be shown
        expect(screen.getByText('Old Test Dataset')).toBeInTheDocument();
    });

    it('shows helpful message when filters return no results', () => {
        render(
            <OldDatasetsPage
                datasets={[]}
                pagination={mockPagination}
                sort={mockSort}
            />
        );

        // Message should guide users to adjust filters
        expect(screen.getByText(/no old datasets found matching your filters/i)).toBeInTheDocument();
        
        // Filters should still be accessible to modify
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
    });

    it('shows filters during loading state', () => {
        render(
            <OldDatasetsPage
                datasets={[]}
                pagination={mockPagination}
                sort={mockSort}
            />
        );

        // Filters should be present (even if disabled during loading)
        expect(screen.getByPlaceholderText(/search title or doi/i)).toBeInTheDocument();
    });
});

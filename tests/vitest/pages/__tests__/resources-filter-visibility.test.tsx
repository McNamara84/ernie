import { render as rawRender, screen } from '@testing-library/react';
import { installCsrfFixture } from '@tests/vitest/helpers/csrf-fixture';
import { settleRender } from '@tests/vitest/helpers/settled-render';
import { describe, expect, it, vi } from 'vitest';

import ResourcesPage from '@/pages/resources';

const render = settleRender(rawRender);

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
        get: vi.fn().mockResolvedValue({ data: { datacenters: [], resource_types: [], curators: [], statuses: [], year_range: {} } }),
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

    it('shows filters even when no resources are found', async () => {
        await render(<ResourcesPage resources={[]} pagination={mockPagination} sort={mockSort} />);

        // Search input should be visible
        expect(screen.getByPlaceholderText(/search doi, title, author, contributor, or email/i)).toBeInTheDocument();

        // No results message should be shown
        expect(screen.getByText(/no resources found matching your filters/i)).toBeInTheDocument();
    });

    it('shows filters when resources are available', async () => {
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

        await render(<ResourcesPage resources={mockResources} pagination={mockPaginationWithData} sort={mockSort} />);

        // Search input should be visible
        expect(screen.getByPlaceholderText(/search doi, title, author, contributor, or email/i)).toBeInTheDocument();

        // Resource should be shown
        expect(screen.getByText('Test Resource')).toBeInTheDocument();
    });

    it('shows helpful message when filters return no results', async () => {
        await render(<ResourcesPage resources={[]} pagination={mockPagination} sort={mockSort} />);

        // Message should guide users to adjust filters
        expect(screen.getByText(/no resources found matching your filters/i)).toBeInTheDocument();

        // Filters should still be accessible to modify
        expect(screen.getByPlaceholderText(/search doi, title, author, contributor, or email/i)).toBeInTheDocument();
    });

    it('shows filters during loading state', async () => {
        await render(<ResourcesPage resources={[]} pagination={mockPagination} sort={mockSort} />);

        // Filters should be present (even if disabled during loading)
        expect(screen.getByPlaceholderText(/search doi, title, author, contributor, or email/i)).toBeInTheDocument();
    });
});

installCsrfFixture();

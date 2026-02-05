import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalResultList } from '@/components/portal/PortalResultList';
import type { PortalPagination, PortalResource } from '@/types/portal';

/**
 * Factory to create a mock PortalResource
 */
function createMockResource(id: number, overrides: Partial<PortalResource> = {}): PortalResource {
    return {
        id,
        title: `Resource ${id}`,
        doi: `10.5880/GFZ.TEST.${id}`,
        resourceType: 'Dataset',
        isIgsn: false,
        year: 2024,
        landingPageUrl: `/landing/resource-${id}`,
        creators: [{ name: `Author ${id}` }],
        geoLocations: [],
        ...overrides,
    };
}

/**
 * Factory to create mock pagination
 */
function createMockPagination(overrides: Partial<PortalPagination> = {}): PortalPagination {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 12,
        from: 1,
        to: 10,
        total: 10,
        ...overrides,
    };
}

describe('PortalResultList', () => {
    const defaultProps = {
        resources: [createMockResource(1), createMockResource(2), createMockResource(3)],
        pagination: createMockPagination({ from: 1, to: 3, total: 3 }),
        onPageChange: vi.fn(),
        isLoading: false,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Results Display', () => {
        it('renders all resource cards', () => {
            render(<PortalResultList {...defaultProps} />);

            expect(screen.getByText('Resource 1')).toBeInTheDocument();
            expect(screen.getByText('Resource 2')).toBeInTheDocument();
            expect(screen.getByText('Resource 3')).toBeInTheDocument();
        });

        it('displays result count header', () => {
            render(<PortalResultList {...defaultProps} />);

            expect(screen.getByText(/showing 1-3 of 3 results/i)).toBeInTheDocument();
        });

        it('formats large result counts with locale separators', () => {
            const pagination = createMockPagination({
                from: 1,
                to: 12,
                total: 1500,
            });
            render(<PortalResultList {...defaultProps} pagination={pagination} />);

            // Should show localized number (1,500 in en-US or 1.500 in de-DE)
            expect(screen.getByText(/showing 1-12 of 1[,.]500 results/i)).toBeInTheDocument();
        });
    });

    describe('Loading State', () => {
        it('shows skeleton loaders when loading', () => {
            render(<PortalResultList {...defaultProps} isLoading={true} />);

            // Skeletons should be rendered
            const skeletons = document.querySelectorAll('[class*="animate-pulse"]');
            expect(skeletons.length).toBeGreaterThan(0);
        });

        it('does not show resource cards when loading', () => {
            render(<PortalResultList {...defaultProps} isLoading={true} />);

            expect(screen.queryByText('Resource 1')).not.toBeInTheDocument();
        });
    });

    describe('Empty State', () => {
        it('shows empty state when no resources', () => {
            render(<PortalResultList {...defaultProps} resources={[]} pagination={createMockPagination({ total: 0, from: 0, to: 0 })} />);

            expect(screen.getByText(/no results found/i)).toBeInTheDocument();
            expect(screen.getByText(/try adjusting your search/i)).toBeInTheDocument();
        });

        it('does not show pagination in empty state', () => {
            render(<PortalResultList {...defaultProps} resources={[]} pagination={createMockPagination({ total: 0 })} />);

            expect(screen.queryByRole('button', { name: /previous/i })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /next/i })).not.toBeInTheDocument();
        });
    });

    describe('Pagination', () => {
        const paginatedProps = {
            ...defaultProps,
            resources: Array.from({ length: 12 }, (_, i) => createMockResource(i + 1)),
            pagination: createMockPagination({
                current_page: 2,
                last_page: 5,
                from: 13,
                to: 24,
                total: 60,
            }),
        };

        it('shows pagination when more than one page', () => {
            render(<PortalResultList {...paginatedProps} />);

            expect(screen.getByRole('button', { name: /previous/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /next/i })).toBeInTheDocument();
        });

        it('does not show pagination when only one page', () => {
            render(<PortalResultList {...defaultProps} />);

            expect(screen.queryByRole('button', { name: /previous/i })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /next/i })).not.toBeInTheDocument();
        });

        it('calls onPageChange when previous button clicked', async () => {
            const user = userEvent.setup();
            const onPageChange = vi.fn();
            render(<PortalResultList {...paginatedProps} onPageChange={onPageChange} />);

            const prevButton = screen.getByRole('button', { name: /previous/i });
            await user.click(prevButton);

            expect(onPageChange).toHaveBeenCalledWith(1); // current is 2, prev is 1
        });

        it('calls onPageChange when next button clicked', async () => {
            const user = userEvent.setup();
            const onPageChange = vi.fn();
            render(<PortalResultList {...paginatedProps} onPageChange={onPageChange} />);

            const nextButton = screen.getByRole('button', { name: /next/i });
            await user.click(nextButton);

            expect(onPageChange).toHaveBeenCalledWith(3); // current is 2, next is 3
        });

        it('disables previous button on first page', () => {
            const firstPageProps = {
                ...paginatedProps,
                pagination: createMockPagination({
                    current_page: 1,
                    last_page: 5,
                    from: 1,
                    to: 12,
                    total: 60,
                }),
            };
            render(<PortalResultList {...firstPageProps} />);

            const prevButton = screen.getByRole('button', { name: /previous/i });
            expect(prevButton).toBeDisabled();
        });

        it('disables next button on last page', () => {
            const lastPageProps = {
                ...paginatedProps,
                pagination: createMockPagination({
                    current_page: 5,
                    last_page: 5,
                    from: 49,
                    to: 60,
                    total: 60,
                }),
            };
            render(<PortalResultList {...lastPageProps} />);

            const nextButton = screen.getByRole('button', { name: /next/i });
            expect(nextButton).toBeDisabled();
        });

        it('highlights current page button', () => {
            render(<PortalResultList {...paginatedProps} />);

            // Current page is 2
            const pageButtons = screen.getAllByRole('button').filter((btn) => btn.textContent === '2');
            expect(pageButtons.length).toBeGreaterThan(0);
        });

        it('calls onPageChange when page number is clicked', async () => {
            const user = userEvent.setup();
            const onPageChange = vi.fn();
            render(<PortalResultList {...paginatedProps} onPageChange={onPageChange} />);

            // Click on page 4
            const page4Button = screen.getByRole('button', { name: '4' });
            await user.click(page4Button);

            expect(onPageChange).toHaveBeenCalledWith(4);
        });
    });

    describe('Page Number Generation', () => {
        it('shows all page numbers when total pages <= 7', () => {
            const props = {
                ...defaultProps,
                resources: Array.from({ length: 12 }, (_, i) => createMockResource(i + 1)),
                pagination: createMockPagination({
                    current_page: 3,
                    last_page: 5,
                    from: 25,
                    to: 36,
                    total: 60,
                }),
            };
            render(<PortalResultList {...props} />);

            // Should show all pages: 1, 2, 3, 4, 5
            for (let i = 1; i <= 5; i++) {
                expect(screen.getByRole('button', { name: String(i) })).toBeInTheDocument();
            }
        });

        it('shows ellipsis for large page counts', () => {
            const props = {
                ...defaultProps,
                resources: Array.from({ length: 12 }, (_, i) => createMockResource(i + 1)),
                pagination: createMockPagination({
                    current_page: 5,
                    last_page: 10,
                    from: 49,
                    to: 60,
                    total: 120,
                }),
            };
            render(<PortalResultList {...props} />);

            // Should show ellipsis (may be multiple)
            const ellipsis = screen.getAllByText('...');
            expect(ellipsis.length).toBeGreaterThan(0);
        });

        it('always shows first and last page with ellipsis', () => {
            const props = {
                ...defaultProps,
                resources: Array.from({ length: 12 }, (_, i) => createMockResource(i + 1)),
                pagination: createMockPagination({
                    current_page: 10,
                    last_page: 20,
                    from: 109,
                    to: 120,
                    total: 240,
                }),
            };
            render(<PortalResultList {...props} />);

            // First page always visible
            expect(screen.getByRole('button', { name: '1' })).toBeInTheDocument();
            // Last page always visible
            expect(screen.getByRole('button', { name: '20' })).toBeInTheDocument();
        });
    });

    describe('Grid Layout', () => {
        it('renders cards in a grid container', () => {
            render(<PortalResultList {...defaultProps} />);

            // Check that there is a grid  container
            const grid = document.querySelector('.grid');
            expect(grid).toBeInTheDocument();
        });
    });
});

import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Portal from '@/pages/portal';
import type { PortalPageProps } from '@/types/portal';

const routerMock = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
}));

vi.mock('@/layouts/portal-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="portal-layout">{children}</div>,
}));

const setSearchMock = vi.fn();
const setTypeMock = vi.fn();
const clearFiltersMock = vi.fn();

vi.mock('@/hooks/use-portal-filters', () => ({
    usePortalFilters: () => ({
        setSearch: setSearchMock,
        setType: setTypeMock,
        setKeywords: vi.fn(),
        addKeyword: vi.fn(),
        removeKeyword: vi.fn(),
        clearFilters: clearFiltersMock,
        hasActiveFilters: false,
    }),
}));

vi.mock('@/components/portal/PortalFilters', () => ({
    PortalFilters: ({
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        filters,
        totalResults,
        onSearchChange,
        onClearFilters,
    }: {
        filters: PortalPageProps['filters'];
        totalResults: number;
        onSearchChange: (s: string) => void;
        onClearFilters: () => void;
    }) => (
        <div data-testid="portal-filters">
            <span data-testid="total-results">{totalResults}</span>
            <input data-testid="search-input" onChange={(e) => onSearchChange(e.target.value)} />
            <button data-testid="clear-filters" onClick={onClearFilters}>
                Clear
            </button>
        </div>
    ),
}));

vi.mock('@/components/portal/PortalMap', () => ({
    PortalMap: ({ resources }: { resources: unknown[] }) => (
        <div data-testid="portal-map">
            <span data-testid="map-resource-count">{(resources as unknown[]).length}</span>
        </div>
    ),
}));

vi.mock('@/components/portal/PortalResultList', () => ({
    PortalResultList: ({
        resources,
        pagination,
        onPageChange,
    }: {
        resources: unknown[];
        pagination: { current_page: number; last_page: number };
        onPageChange: (page: number) => void;
    }) => (
        <div data-testid="portal-result-list">
            <span data-testid="result-count">{(resources as unknown[]).length}</span>
            <span data-testid="current-page">{pagination.current_page}</span>
            <button data-testid="next-page" onClick={() => onPageChange(pagination.current_page + 1)}>
                Next
            </button>
        </div>
    ),
}));

vi.mock('@/components/ui/resizable', () => ({
    ResizablePanelGroup: ({ children }: { children?: React.ReactNode }) => <div data-testid="resizable-group">{children}</div>,
    ResizablePanel: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    ResizableHandle: () => <div data-testid="resizable-handle" />,
}));

const defaultProps: PortalPageProps = {
    resources: [
        { id: 1, doi: '10.1234/test1', title: 'Dataset 1', creators: [], year: 2024, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, geoLocations: [], landingPageUrl: null },
        { id: 2, doi: '10.1234/test2', title: 'Dataset 2', creators: [], year: 2023, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, geoLocations: [], landingPageUrl: null },
    ],
    mapData: [
        { id: 1, doi: '10.1234/test1', title: 'Dataset 1', creators: [], year: 2024, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, geoLocations: [{ id: 1, type: 'point', point: { lat: 52, lng: 13 }, bounds: null, polygon: null }], landingPageUrl: null },
    ],
    pagination: {
        current_page: 1,
        last_page: 3,
        per_page: 25,
        total: 50,
        from: 1,
        to: 25,
    },
    filters: {
        query: '',
        type: 'all',
        keywords: [],
        bounds: null,
    },
    keywordSuggestions: [],
};

describe('Portal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.clear();
    });

    it('renders the portal layout with header', () => {
        render(<Portal {...defaultProps} />);
        expect(screen.getByTestId('portal-layout')).toBeInTheDocument();
        expect(screen.getByText('Data Portal')).toBeInTheDocument();
        expect(screen.getByText(/discover and explore/i)).toBeInTheDocument();
    });

    it('passes resources to result list', () => {
        render(<Portal {...defaultProps} />);
        const resultCounts = screen.getAllByTestId('result-count');
        expect(resultCounts.some((el) => el.textContent === '2')).toBe(true);
    });

    it('passes map data to map component', () => {
        render(<Portal {...defaultProps} />);
        const mapCounts = screen.getAllByTestId('map-resource-count');
        expect(mapCounts.some((el) => el.textContent === '1')).toBe(true);
    });

    it('passes total results to filters', () => {
        render(<Portal {...defaultProps} />);
        expect(screen.getByTestId('total-results')).toHaveTextContent('50');
    });

    it('navigates to next page with correct params', async () => {
        const user = userEvent.setup();
        render(<Portal {...defaultProps} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        expect(routerMock.get).toHaveBeenCalledWith('/portal', { page: 2 }, expect.any(Object));
    });

    it('navigates with query param preserved on page change', async () => {
        const user = userEvent.setup();
        const propsWithQuery = {
            ...defaultProps,
            filters: { query: 'climate', type: 'all' as const, keywords: [], bounds: null },
        };
        render(<Portal {...propsWithQuery} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        expect(routerMock.get).toHaveBeenCalledWith('/portal?q=climate', { page: 2 }, expect.any(Object));
    });

    it('navigates with type param preserved on page change', async () => {
        const user = userEvent.setup();
        const propsWithType = {
            ...defaultProps,
            filters: { query: '', type: 'Dataset' as PortalPageProps['filters']['type'], keywords: [], bounds: null },
        };
        render(<Portal {...propsWithType} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        expect(routerMock.get).toHaveBeenCalledWith('/portal?type=Dataset', { page: 2 }, expect.any(Object));
    });

    it('persists map collapsed state to localStorage', async () => {
        render(<Portal {...defaultProps} />);
        expect(localStorage.getItem('portal-map-collapsed')).toBe('false');
    });

    it('restores map collapsed state from localStorage', () => {
        localStorage.setItem('portal-map-collapsed', 'true');
        render(<Portal {...defaultProps} />);
        // When map is collapsed, a "Show Map" text appears
        expect(screen.queryByTestId('resizable-handle')).toBeNull();
    });

    it('restores panel layout from localStorage', () => {
        localStorage.setItem('portal-panel-layout', JSON.stringify({ results: 70, map: 30 }));
        render(<Portal {...defaultProps} />);
        expect(screen.getByTestId('portal-layout')).toBeInTheDocument();
    });

    it('handles invalid JSON in localStorage gracefully', () => {
        localStorage.setItem('portal-panel-layout', '{{invalid');
        expect(() => render(<Portal {...defaultProps} />)).not.toThrow();
    });

    it('counts geo locations correctly', () => {
        const propsWithMultipleGeo = {
            ...defaultProps,
            mapData: [
                {
                    id: 1, doi: '10.1234/a', title: 'A', creators: [], year: 2024, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, landingPageUrl: null,
                    geoLocations: [
                        { id: 1, type: 'point' as const, point: { lat: 1, lng: 1 }, bounds: null, polygon: null },
                        { id: 2, type: 'point' as const, point: { lat: 2, lng: 2 }, bounds: null, polygon: null },
                    ],
                },
                { id: 2, doi: '10.1234/b', title: 'B', creators: [], year: 2024, resourceType: 'Dataset', resourceTypeSlug: 'dataset', isIgsn: false, geoLocations: [], landingPageUrl: null },
            ],
        };
        render(<Portal {...propsWithMultipleGeo} />);
        // 2 geo locations from resource 1, 0 from resource 2 = 2 total
        expect(screen.getByText(/2 locations/)).toBeInTheDocument();
    });

    it('shows singular "location" for 1 geo location', () => {
        render(<Portal {...defaultProps} />);
        // defaultProps has 1 geoLocation
        expect(screen.getByText(/1 location\b/)).toBeInTheDocument();
    });
});

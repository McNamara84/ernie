import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { act, fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Portal from '@/pages/portal';
import type { PortalBasemapConfig, PortalPageProps, PortalPagination } from '@/types/portal';

const routerMock = vi.hoisted(() => ({ get: vi.fn(), on: vi.fn((_event: string, _callback: () => void) => vi.fn()) }));
const usePageMock = vi.hoisted(() => vi.fn());
const axiosGetMock = vi.hoisted(() => vi.fn());
const isDesktopMock = vi.hoisted(() => vi.fn(() => true));
const headTitleMock = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }) => {
        headTitleMock(title);
        return null;
    },
    router: routerMock,
    usePage: usePageMock,
}));

vi.mock('axios', () => ({
    default: { get: axiosGetMock },
}));

vi.mock('@/hooks/use-media-query', () => ({
    useMediaQuery: () => isDesktopMock(),
}));

vi.mock('@/layouts/portal-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="portal-layout">{children}</div>,
}));

const setSearchMock = vi.fn();
const setTypeMock = vi.fn();
const setKeywordsMock = vi.fn();
const setFreeKeywordsMock = vi.fn();
const setSearchAndKeywordsMock = vi.fn();
const setThesaurusKeywordsMock = vi.fn();
const setSampleTypesMock = vi.fn();
const setMaterialsMock = vi.fn();
const setClassificationsMock = vi.fn();
const setGeologicalAgesMock = vi.fn();
const setGeologicalUnitsMock = vi.fn();
const clearFiltersMock = vi.fn();
const setBoundsMock = vi.fn();
const setMapFilterEnabledMock = vi.fn();
const setTemporalMock = vi.fn();
const useNavigationStatusMock = vi.fn();

vi.mock('@/hooks/use-portal-filters', () => ({
    usePortalFilters: () => ({
        setSearch: setSearchMock,
        setType: setTypeMock,
        setDatacenter: vi.fn(),
        setKeywords: setKeywordsMock,
        addKeyword: vi.fn(),
        removeKeyword: vi.fn(),
        setFreeKeywords: setFreeKeywordsMock,
        setSearchAndKeywords: setSearchAndKeywordsMock,
        setThesaurusKeywords: setThesaurusKeywordsMock,
        setSampleTypes: setSampleTypesMock,
        setMaterials: setMaterialsMock,
        setClassifications: setClassificationsMock,
        setGeologicalAges: setGeologicalAgesMock,
        setGeologicalUnits: setGeologicalUnitsMock,
        setBounds: setBoundsMock,
        setMapFilterEnabled: setMapFilterEnabledMock,
        setTemporal: setTemporalMock,
        clearFilters: clearFiltersMock,
        hasActiveFilters: false,
    }),
}));

vi.mock('@/hooks/use-navigation-status', () => ({
    useNavigationStatus: () => useNavigationStatusMock(),
}));

vi.mock('@/components/portal/PortalFilters', () => ({
    PortalFilters: ({
        searchValue,
        onSearchValueChange,
        onSearchChange,
        onKeywordSelect,
        onKeywordsChange,
        onClearFilters,
        geoFilterEnabled,
        onGeoFilterToggle,
        onBoundsChange,
        temporalFilterEnabled,
        onTemporalFilterToggle,
        onTemporalChange,
        showResourceTypeFilter,
        igsnFacets,
        onSampleTypesChange,
        onMaterialsChange,
        onClassificationsChange,
        onGeologicalAgesChange,
        onGeologicalUnitsChange,
    }: {
        searchValue: string;
        onSearchValueChange: (s: string) => void;
        onSearchChange: (s: string) => void;
        onKeywordSelect?: (keyword: string) => void;
        onKeywordsChange?: (keywords: string[]) => void;
        onClearFilters: () => void;
        geoFilterEnabled?: boolean;
        onGeoFilterToggle?: (enabled: boolean) => void;
        onBoundsChange?: (bounds: { north: number; south: number; east: number; west: number } | null) => void;
        temporalFilterEnabled?: boolean;
        onTemporalFilterToggle?: (enabled: boolean) => void;
        onTemporalChange?: (temporal: { dateType: string; yearFrom: number; yearTo: number } | null) => void;
        showResourceTypeFilter?: boolean;
        igsnFacets?: { sampleTypes: unknown[] } | null;
        onSampleTypesChange?: (values: string[]) => void;
        onMaterialsChange?: (values: string[]) => void;
        onClassificationsChange?: (values: string[]) => void;
        onGeologicalAgesChange?: (values: string[]) => void;
        onGeologicalUnitsChange?: (values: string[]) => void;
    }) => (
        <div data-testid="portal-filters">
            <input data-testid="search-input" value={searchValue} onChange={(e) => onSearchValueChange(e.target.value)} />
            <button data-testid="submit-search" onClick={() => onSearchChange(searchValue)}>
                Search
            </button>
            {onKeywordSelect && (
                <button data-testid="keyword-select" onClick={() => onKeywordSelect('Seismology')}>
                    Select Keyword
                </button>
            )}
            {onKeywordsChange && (
                <button data-testid="keyword-change" onClick={() => onKeywordsChange(['Updated Keyword'])}>
                    Keyword Change
                </button>
            )}
            <button data-testid="clear-filters" onClick={onClearFilters}>
                Clear
            </button>
            <span data-testid="geo-filter-enabled">{String(geoFilterEnabled ?? false)}</span>
            <span data-testid="resource-type-filter-visible">{String(showResourceTypeFilter ?? true)}</span>
            <span data-testid="igsn-sample-facet-count">{igsnFacets?.sampleTypes.length ?? 0}</span>
            <button data-testid="set-sample-types" onClick={() => onSampleTypesChange?.(['Core'])} />
            <button data-testid="set-materials" onClick={() => onMaterialsChange?.(['Rock'])} />
            <button data-testid="set-classifications" onClick={() => onClassificationsChange?.(['Igneous'])} />
            <button data-testid="set-geological-ages" onClick={() => onGeologicalAgesChange?.(['Jurassic'])} />
            <button data-testid="set-geological-units" onClick={() => onGeologicalUnitsChange?.(['Unit A'])} />
            {onGeoFilterToggle && (
                <button data-testid="geo-toggle" onClick={() => onGeoFilterToggle(!geoFilterEnabled)}>
                    Toggle Geo
                </button>
            )}
            {onBoundsChange && (
                <>
                    <button data-testid="apply-bounds" onClick={() => onBoundsChange({ north: 53, south: 51, east: 14, west: 12 })}>
                        Apply Bounds
                    </button>
                    <button data-testid="clear-bounds" onClick={() => onBoundsChange(null)}>
                        Clear Bounds
                    </button>
                </>
            )}
            <span data-testid="temporal-filter-enabled">{String(temporalFilterEnabled ?? false)}</span>
            {onTemporalFilterToggle && (
                <button data-testid="temporal-toggle" onClick={() => onTemporalFilterToggle(!temporalFilterEnabled)}>
                    Toggle Temporal
                </button>
            )}
            {onTemporalChange && (
                <>
                    <button data-testid="apply-temporal" onClick={() => onTemporalChange({ dateType: 'Created', yearFrom: 2010, yearTo: 2020 })}>
                        Apply Temporal
                    </button>
                    <button data-testid="clear-temporal" onClick={() => onTemporalChange(null)}>
                        Clear Temporal
                    </button>
                </>
            )}
        </div>
    ),
}));

vi.mock('@/components/portal/PortalMap', () => ({
    PortalMap: ({
        filters,
        maxZoom,
        basemap,
        onViewportChange,
        onLocationCountChange,
        geoFilterEnabled,
    }: {
        filters: { query: string | null };
        maxZoom: number;
        basemap: PortalBasemapConfig;
        onViewportChange?: (bounds: { north: number; south: number; east: number; west: number }) => void;
        onLocationCountChange?: (count: number) => void;
        geoFilterEnabled?: boolean;
    }) => (
        <div data-testid="portal-map">
            <span data-testid="map-filter-query">{filters.query ?? ''}</span>
            <span data-testid="map-max-zoom">{maxZoom}</span>
            <span data-testid="map-basemap">{`${basemap.provider}:${basemap.styleUrl}:${basemap.language}`}</span>
            <span data-testid="map-geo-enabled">{String(geoFilterEnabled ?? false)}</span>
            {onViewportChange && (
                <button data-testid="trigger-viewport-change" onClick={() => onViewportChange({ north: 54, south: 50, east: 15, west: 11 })}>
                    Viewport Change
                </button>
            )}
            {onLocationCountChange && (
                <button data-testid="report-location-count" onClick={() => onLocationCountChange(2)}>
                    Report locations
                </button>
            )}
        </div>
    ),
}));

vi.mock('@/components/portal/PortalResultList', () => ({
    PortalResultList: ({
        resources,
        pagination,
        onPageChange,
        isLoading,
        onRetryCount,
    }: {
        resources: unknown[];
        pagination: PortalPagination;
        onPageChange: (page: number) => void;
        isLoading?: boolean;
        onRetryCount?: () => void;
    }) => (
        <div data-testid="portal-result-list">
            <span data-testid="result-count">{(resources as unknown[]).length}</span>
            <span data-testid="current-page">{pagination.current_page}</span>
            <span data-testid="page-range">{`${pagination.from}-${pagination.to}`}</span>
            <span data-testid="last-page">{pagination.last_page}</span>
            <span data-testid="result-loading">{String(isLoading ?? false)}</span>
            <span data-testid="total-results">{pagination.total}</span>
            <span data-testid="count-status">{pagination.count_status}</span>
            {pagination.count_status === 'pending' && <span>Counting results...</span>}
            {pagination.total !== null && <span>{pagination.total} results</span>}
            {pagination.count_status === 'failed' && onRetryCount && <button onClick={onRetryCount}>Retry count</button>}
            {isLoading && <span data-testid="portal-results-refreshing">Refreshing results...</span>}
            <button data-testid="next-page" onClick={() => onPageChange(pagination.current_page + 1)}>
                Next
            </button>
        </div>
    ),
}));

vi.mock('@/components/ui/resizable', () => ({
    ResizablePanelGroup: ({
        children,
        onLayoutChanged,
    }: {
        children?: React.ReactNode;
        onLayoutChanged?: (layout: Record<string, number>) => void;
    }) => (
        <div data-testid="resizable-group">
            {children}
            {onLayoutChanged && (
                <button data-testid="trigger-layout-change" onClick={() => onLayoutChanged({ results: 60, map: 40 })}>
                    Layout Change
                </button>
            )}
        </div>
    ),
    ResizablePanel: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    ResizableHandle: () => <div data-testid="resizable-handle" />,
}));

const defaultProps: PortalPageProps = {
    portal: {
        kind: 'doi',
        title: 'Data Portal',
        basePath: '/doi-search',
        showResourceTypeFilter: true,
    },
    mapConfig: {
        maxZoom: 7,
        basemap: { provider: 'openfreemap', styleUrl: 'https://tiles.openfreemap.org/styles/liberty', language: 'en' },
    },
    resources: [
        {
            id: 1,
            doi: '10.1234/test1',
            title: 'Dataset 1',
            creators: [],
            searchMatches: [],
            year: 2024,
            resourceType: 'Dataset',
            resourceTypeSlug: 'dataset',
            isIgsn: false,
            geoLocations: [],
            landingPageUrl: null,
        },
        {
            id: 2,
            doi: '10.1234/test2',
            title: 'Dataset 2',
            creators: [],
            searchMatches: [],
            year: 2023,
            resourceType: 'Dataset',
            resourceTypeSlug: 'dataset',
            isIgsn: false,
            geoLocations: [],
            landingPageUrl: null,
        },
    ],
    pagination: {
        current_page: 1,
        last_page: 3,
        per_page: 25,
        total: 50,
        from: 1,
        to: 25,
        has_more: true,
        count_status: 'ready',
        filter_fingerprint: 'default-fingerprint',
    },
    filters: {
        query: '',
        type: [],
        keywords: [],
        freeKeywords: [],
        thesaurusKeywords: [],
        sampleTypes: [],
        materials: [],
        classifications: [],
        geologicalAges: [],
        geologicalUnits: [],
        datacenter: [],
        bounds: null,
        temporal: null,
    },
    temporalRange: { Created: { min: 2000, max: 2024 } },
    resourceTypeFacets: [
        { slug: 'dataset', name: 'Dataset', count: 30 },
        { slug: 'software', name: 'Software', count: 15 },
        { slug: 'physical-object', name: 'IGSN Samples', count: 5 },
    ],
    datacenterFacets: [],
};

const pendingPagination: PortalPagination = {
    ...defaultProps.pagination,
    filter_fingerprint: 'current-filter',
    per_page: 20,
    to: 20,
    total: null,
    last_page: null,
    count_status: 'pending',
};
const secondPage = { ...pendingPagination, current_page: 2, from: 21, to: 40 };
const countResponse = { data: { filter_fingerprint: 'current-filter', total: 45, last_page: 3, count_status: 'ready' } };

function resultPage(pagination: PortalPagination) {
    const props = { ...defaultProps, pagination };
    usePageMock.mockReturnValue({ props });
    return <Portal {...props} />;
}

describe('Portal', () => {
    beforeEach(() => {
        axiosGetMock.mockReset();
        usePageMock.mockReturnValue({ props: defaultProps });
        headTitleMock.mockReset();
        localStorage.clear();
        window.history.replaceState(null, '', '/doi-search');
        useNavigationStatusMock.mockReturnValue({ isNavigating: false, statusText: 'Ready' });
        isDesktopMock.mockReturnValue(true);
    });

    it('renders the portal layout', () => {
        render(<Portal {...defaultProps} />);
        expect(screen.getByTestId('portal-layout')).toBeInTheDocument();
        expect(screen.getAllByTestId('map-max-zoom')[0]).toHaveTextContent('7');
        expect(screen.getAllByTestId('map-basemap')[0]).toHaveTextContent('openfreemap:https://tiles.openfreemap.org/styles/liberty:en');
    });

    it('uses the configured title and hides the IGSN resource type filter', () => {
        render(<Portal {...defaultProps} portal={{ kind: 'igsn', title: 'IGSN Portal', basePath: '/igsn-search', showResourceTypeFilter: false }} />);

        expect(headTitleMock).toHaveBeenCalledWith('IGSN Portal');
        expect(screen.getByTestId('resource-type-filter-visible')).toHaveTextContent('false');
    });

    it('forwards IGSN facets and all five filter actions to desktop and mobile controls', async () => {
        const user = userEvent.setup();
        render(
            <Portal
                {...defaultProps}
                portal={{ kind: 'igsn', title: 'IGSN Portal', basePath: '/igsn-search', showResourceTypeFilter: false }}
                igsnFacets={{
                    sampleTypes: [{ value: 'Core', label: 'Core', count: 1 }],
                    materials: [],
                    classifications: [],
                    geologicalAges: [],
                    geologicalUnits: [],
                }}
            />,
        );

        expect(screen.getAllByTestId('igsn-sample-facet-count').every((element) => element.textContent === '1')).toBe(true);

        await user.click(screen.getAllByTestId('set-sample-types')[0]);
        await user.click(screen.getAllByTestId('set-materials')[0]);
        await user.click(screen.getAllByTestId('set-classifications')[0]);
        await user.click(screen.getAllByTestId('set-geological-ages')[0]);
        await user.click(screen.getAllByTestId('set-geological-units')[0]);

        expect(setSampleTypesMock).toHaveBeenCalledWith(['Core']);
        expect(setMaterialsMock).toHaveBeenCalledWith(['Rock']);
        expect(setClassificationsMock).toHaveBeenCalledWith(['Igneous']);
        expect(setGeologicalAgesMock).toHaveBeenCalledWith(['Jurassic']);
        expect(setGeologicalUnitsMock).toHaveBeenCalledWith(['Unit A']);
    });

    it('passes resources to result list', () => {
        render(<Portal {...defaultProps} />);
        const resultCounts = screen.getAllByTestId('result-count');
        expect(resultCounts.some((el) => el.textContent === '2')).toBe(true);
    });

    it('renders results while counting and applies a matching count response', async () => {
        axiosGetMock.mockResolvedValueOnce({
            data: {
                filter_fingerprint: 'current-filter',
                total: 7,
                last_page: 1,
                count_status: 'ready',
            },
        });

        render(<Portal {...defaultProps} pagination={pendingPagination} />);

        expect(screen.getAllByTestId('result-count')[0]).toHaveTextContent('2');
        expect(screen.getByText('Counting results...')).toBeInTheDocument();
        await waitFor(() => expect(screen.getByTestId('total-results')).toHaveTextContent('7'));
        expect(screen.getByText('7 results')).toBeInTheDocument();
    });

    it('keeps the resolved count when Inertia supplies the next result page with the same filters', async () => {
        axiosGetMock.mockResolvedValue(countResponse);
        const { rerender } = render(resultPage(pendingPagination));
        await screen.findByText('45 results');

        rerender(resultPage(secondPage));

        expect(screen.getByTestId('current-page')).toHaveTextContent('2');
        expect(screen.getByTestId('page-range')).toHaveTextContent('21-40');
        expect(screen.getByTestId('last-page')).toHaveTextContent('3');
        expect(screen.getByText('45 results')).toBeInTheDocument();
        expect(axiosGetMock).toHaveBeenCalledTimes(1);
    });

    it('revalidates an expired count on the next result page and shares the in-flight refresh across pages', async () => {
        const countedAt = Date.now();
        using clock = vi.spyOn(Date, 'now').mockReturnValue(countedAt);
        const refresh = Promise.withResolvers<typeof countResponse>();
        axiosGetMock.mockResolvedValueOnce(countResponse).mockReturnValueOnce(refresh.promise);
        const { rerender } = render(resultPage(pendingPagination));
        await screen.findByText('45 results');

        clock.mockReturnValue(countedAt + 29_999);
        rerender(resultPage(pendingPagination));
        expect(axiosGetMock).toHaveBeenCalledTimes(1);

        clock.mockReturnValue(countedAt + 30_000);
        // A local render still has the same Inertia response.
        rerender(<Portal {...defaultProps} pagination={pendingPagination} />);
        expect(axiosGetMock).toHaveBeenCalledTimes(1);
        rerender(resultPage(secondPage));
        expect(axiosGetMock).toHaveBeenCalledTimes(2);
        expect(screen.getByTestId('page-range')).toHaveTextContent('21-40');
        expect(screen.getByText('45 results')).toBeInTheDocument();
        expect(screen.getByTestId('last-page')).toHaveTextContent('3');

        rerender(resultPage(pendingPagination));
        expect(axiosGetMock).toHaveBeenCalledTimes(2);
        expect(axiosGetMock.mock.calls[1][1].signal.aborted).toBe(false);
        refresh.resolve({ data: { ...countResponse.data, total: 65, last_page: 4 } });
        await screen.findByText('65 results');
        expect(screen.getByTestId('last-page')).toHaveTextContent('4');
        expect(screen.getByTestId('current-page')).toHaveTextContent('1');

        clock.mockReturnValue(countedAt + 45_000);
        rerender(resultPage(secondPage));
        expect(screen.getByText('65 results')).toBeInTheDocument();
        expect(axiosGetMock).toHaveBeenCalledTimes(2);
    });

    it('keeps the cached count after a failed refresh and retries on an identical search response', async () => {
        const countedAt = Date.now();
        using clock = vi.spyOn(Date, 'now').mockReturnValue(countedAt);
        axiosGetMock
            .mockResolvedValueOnce(countResponse)
            .mockRejectedValueOnce(new Error('count unavailable'))
            .mockResolvedValueOnce({ data: { ...countResponse.data, total: 25, last_page: 2 } });
        const { rerender } = render(resultPage(pendingPagination));
        await screen.findByText('45 results');

        clock.mockReturnValue(countedAt + 30_000);
        await act(async () => rerender(resultPage(pendingPagination)));
        expect(axiosGetMock).toHaveBeenCalledTimes(2);
        expect(screen.getByText('45 results')).toBeInTheDocument();
        expect(screen.getByTestId('last-page')).toHaveTextContent('3');
        expect(screen.getByTestId('count-status')).toHaveTextContent('ready');

        rerender(resultPage(pendingPagination));
        await screen.findByText('25 results');
        expect(screen.getByTestId('last-page')).toHaveTextContent('2');
        expect(axiosGetMock).toHaveBeenCalledTimes(3);
    });

    it('uses the supplied page range without requesting a count when the fingerprint is missing', () => {
        render(<Portal {...defaultProps} pagination={{ ...secondPage, filter_fingerprint: '' }} />);

        expect(screen.getByTestId('page-range')).toHaveTextContent('21-40');
        expect(screen.getByTestId('total-results')).toBeEmptyDOMElement();
        expect(axiosGetMock).not.toHaveBeenCalled();
    });

    it('finishes a slow count on the currently displayed page without restarting it', async () => {
        const request = Promise.withResolvers<typeof countResponse>();
        axiosGetMock.mockReturnValue(request.promise);
        const { rerender } = render(<Portal {...defaultProps} pagination={pendingPagination} />);

        rerender(<Portal {...defaultProps} filters={{ ...defaultProps.filters }} pagination={secondPage} />);
        expect(screen.getByTestId('page-range')).toHaveTextContent('21-40');
        expect(screen.getByText('Counting results...')).toBeInTheDocument();
        expect(axiosGetMock.mock.calls[0][1].signal.aborted).toBe(false);

        request.resolve(countResponse);
        await screen.findByText('45 results');
        expect(screen.getByTestId('current-page')).toHaveTextContent('2');
        expect(screen.getByTestId('last-page')).toHaveTextContent('3');
        expect(axiosGetMock).toHaveBeenCalledTimes(1);
    });

    it('uses a server-provided count on subsequent pages and discards an older outstanding count', async () => {
        const request = Promise.withResolvers<typeof countResponse>();
        axiosGetMock.mockReturnValueOnce(request.promise);
        const { rerender } = render(<Portal {...defaultProps} pagination={pendingPagination} />);
        const signal: AbortSignal = axiosGetMock.mock.calls[0][1].signal;

        rerender(<Portal {...defaultProps} pagination={{ ...secondPage, count_status: 'ready', total: 78, last_page: 4 }} />);
        expect(screen.getByText('78 results')).toBeInTheDocument();
        expect(signal.aborted).toBe(true);
        await act(async () => request.resolve(countResponse));

        rerender(<Portal {...defaultProps} pagination={pendingPagination} />);
        expect(screen.getByText('78 results')).toBeInTheDocument();
        expect(screen.getByTestId('last-page')).toHaveTextContent('4');
        expect(screen.getByTestId('current-page')).toHaveTextContent('1');
        expect(axiosGetMock).toHaveBeenCalledTimes(1);
    });

    it.each([
        { change: 'filters', pagination: { ...pendingPagination, filter_fingerprint: 'new-filter' }, portal: defaultProps.portal },
        { change: 'page size', pagination: { ...pendingPagination, per_page: 10 }, portal: defaultProps.portal },
        {
            change: 'portal',
            pagination: pendingPagination,
            portal: { kind: 'igsn', title: 'IGSN Portal', basePath: '/igsn-search', showResourceTypeFilter: false } as const,
        },
    ])('cancels the old request and ignores its late response after a change of $change', async ({ pagination, portal }) => {
        const oldRequest = Promise.withResolvers<typeof countResponse>();
        const newRequest = Promise.withResolvers<typeof countResponse>();
        axiosGetMock.mockReturnValueOnce(oldRequest.promise).mockReturnValueOnce(newRequest.promise);
        const { rerender } = render(<Portal {...defaultProps} pagination={pendingPagination} />);
        const signal: AbortSignal = axiosGetMock.mock.calls[0][1].signal;

        rerender(<Portal {...defaultProps} portal={portal} pagination={pagination} />);
        expect(signal.aborted).toBe(true);
        expect(axiosGetMock).toHaveBeenLastCalledWith(`${portal.basePath}/count`, expect.objectContaining({ signal: expect.anything() }));
        newRequest.resolve({ data: { ...countResponse.data, filter_fingerprint: pagination.filter_fingerprint, total: 7, last_page: 1 } });
        await screen.findByText('7 results');

        await act(async () => oldRequest.resolve(countResponse));
        expect(screen.getByText('7 results')).toBeInTheDocument();
        expect(screen.getByTestId('last-page')).toHaveTextContent('1');
        expect(axiosGetMock).toHaveBeenCalledTimes(2);
    });

    it('clears a resolved count as soon as the active search changes', async () => {
        const newRequest = Promise.withResolvers<typeof countResponse>();
        axiosGetMock.mockResolvedValueOnce(countResponse).mockReturnValueOnce(newRequest.promise);
        const { rerender } = render(<Portal {...defaultProps} pagination={pendingPagination} />);
        await screen.findByText('45 results');

        rerender(<Portal {...defaultProps} pagination={{ ...pendingPagination, filter_fingerprint: 'empty-search' }} />);
        expect(screen.getByTestId('last-page')).toBeEmptyDOMElement();
        expect(screen.getByTestId('total-results')).toBeEmptyDOMElement();
        newRequest.resolve({ data: { ...countResponse.data, filter_fingerprint: 'empty-search', total: 0, last_page: 1 } });
        await screen.findByText('0 results');
        expect(screen.getByTestId('last-page')).toHaveTextContent('1');
    });

    it('ignores a portal count response from a stale filter', async () => {
        axiosGetMock.mockResolvedValueOnce({
            data: {
                filter_fingerprint: 'stale-filter',
                total: 99,
                last_page: 5,
                count_status: 'ready',
            },
        });

        render(<Portal {...defaultProps} pagination={pendingPagination} />);

        await waitFor(() => expect(axiosGetMock).toHaveBeenCalled());
        expect(screen.getByText('Counting results...')).toBeInTheDocument();
        expect(screen.getByTestId('total-results')).toBeEmptyDOMElement();
    });

    it('preserves exact direct-URL filters in the asynchronous count request', async () => {
        window.history.replaceState(
            null,
            '',
            '/doi-search?q=%20climate%20&north=53.123456789&south=50.987654321&east=15.000000009&west=11.000000001&page=4',
        );
        axiosGetMock.mockResolvedValueOnce({
            data: {
                filter_fingerprint: 'current-filter',
                total: 7,
                last_page: 1,
                count_status: 'ready',
            },
        });

        render(
            <Portal
                {...defaultProps}
                filters={{
                    ...defaultProps.filters,
                    query: ' climate ',
                    bounds: { north: 53.123456789, south: 50.987654321, east: 15.000000009, west: 11.000000001 },
                }}
                pagination={pendingPagination}
            />,
        );

        await waitFor(() => expect(axiosGetMock).toHaveBeenCalledTimes(1));
        const countUrl = new URL(String(axiosGetMock.mock.calls[0][0]), 'https://ernie.test');

        expect(countUrl.pathname).toBe('/doi-search/count');
        expect(countUrl.searchParams.get('q')).toBe(' climate ');
        expect(countUrl.searchParams.get('north')).toBe('53.123456789');
        expect(countUrl.searchParams.has('page')).toBe(false);
    });

    it('retries a failed count on a directly opened second page', async () => {
        window.history.replaceState(null, '', '/doi-search?q=climate&page=2');
        axiosGetMock.mockRejectedValueOnce(new Error('count failed')).mockResolvedValueOnce(countResponse);
        render(<Portal {...defaultProps} pagination={secondPage} filters={{ ...defaultProps.filters, query: 'climate' }} />);

        const retry = await screen.findByRole('button', { name: /retry count/i });
        expect(screen.getAllByTestId('result-count')[0]).toHaveTextContent('2');
        expect(screen.getByTestId('count-status')).toHaveTextContent('failed');
        await userEvent.setup().click(retry);
        await screen.findByText('45 results');
        expect(screen.getByTestId('current-page')).toHaveTextContent('2');
        expect(screen.getByTestId('page-range')).toHaveTextContent('21-40');
        expect(screen.getByTestId('last-page')).toHaveTextContent('3');
        expect(axiosGetMock.mock.calls.map(([url]) => url)).toEqual(['/doi-search/count?q=climate', '/doi-search/count?q=climate']);
    });

    it('shows refresh feedback when portal navigation is pending', () => {
        useNavigationStatusMock.mockReturnValueOnce({ isNavigating: true, statusText: 'Refreshing results...' });

        render(<Portal {...defaultProps} />);

        expect(screen.getByTestId('portal-results-refreshing').textContent).toMatch(/refreshing results/i);
        expect(screen.getAllByTestId('result-loading')[0]).toHaveTextContent('true');
    });

    it('passes active filters to the asynchronous map component', () => {
        render(<Portal {...defaultProps} />);
        expect(screen.getAllByTestId('map-filter-query')[0]).toHaveTextContent('');
    });

    it('shows total results only in the result list', () => {
        render(<Portal {...defaultProps} />);
        expect(screen.getByTestId('total-results')).toHaveTextContent('50');
        expect(screen.getByTestId('portal-filters')).not.toHaveTextContent('50 results');
    });

    it('navigates to next page with correct params', async () => {
        const user = userEvent.setup();
        render(<Portal {...defaultProps} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        expect(routerMock.get).toHaveBeenCalledWith('/doi-search', { page: 2 }, expect.any(Object));
    });

    it('navigates with query param preserved on page change', async () => {
        const user = userEvent.setup();
        const propsWithQuery = {
            ...defaultProps,
            filters: { ...defaultProps.filters, query: 'climate', type: [] as string[], keywords: [], datacenter: [], bounds: null, temporal: null },
        };
        render(<Portal {...propsWithQuery} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        expect(routerMock.get).toHaveBeenCalledWith('/doi-search?q=climate', { page: 2 }, expect.any(Object));
    });

    it('navigates with type param preserved on page change', async () => {
        const user = userEvent.setup();
        const propsWithType = {
            ...defaultProps,
            filters: { ...defaultProps.filters, query: '', type: ['dataset'], keywords: [], datacenter: [], bounds: null, temporal: null },
        };
        render(<Portal {...propsWithType} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        expect(routerMock.get).toHaveBeenCalledWith(expect.stringContaining('type%5B%5D=dataset'), { page: 2 }, expect.any(Object));
    });

    it('preserves free keyword and thesaurus params on page change', async () => {
        const user = userEvent.setup();
        const propsWithSplitKeywords = {
            ...defaultProps,
            filters: {
                ...defaultProps.filters,
                query: '',
                type: [] as string[],
                keywords: [],
                freeKeywords: ['Seismology'],
                thesaurusKeywords: ['earth-science'],
                datacenter: [],
                bounds: null,
                temporal: null,
            },
        };
        render(<Portal {...propsWithSplitKeywords} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        const calledUrl = routerMock.get.mock.calls[0][0] as string;
        expect(calledUrl).toContain('free_keywords%5B%5D=Seismology');
        expect(calledUrl).toContain('thesaurus_keywords%5B%5D=earth-science');
    });

    it('drops legacy keywords on page change when split keyword filters are active', async () => {
        const user = userEvent.setup();
        const propsWithMixedKeywordFilters = {
            ...defaultProps,
            filters: {
                ...defaultProps.filters,
                query: '',
                type: [] as string[],
                keywords: ['Legacy Keyword'],
                freeKeywords: ['Seismology'],
                thesaurusKeywords: ['earth-science'],
                datacenter: [],
                bounds: null,
                temporal: null,
            },
        };

        render(<Portal {...propsWithMixedKeywordFilters} />);

        const nextButtons = screen.getAllByTestId('next-page');
        await user.click(nextButtons[0]);

        const calledUrl = routerMock.get.mock.calls[0][0] as string;
        expect(calledUrl).toContain('free_keywords%5B%5D=Seismology');
        expect(calledUrl).toContain('thesaurus_keywords%5B%5D=earth-science');
        expect(calledUrl).not.toContain('keywords%5B%5D=Legacy+Keyword');
    });

    it('routes legacy keyword edits through setKeywords for old bookmarked URLs', async () => {
        const user = userEvent.setup();
        const propsWithLegacyKeywords = {
            ...defaultProps,
            filters: {
                ...defaultProps.filters,
                keywords: ['Legacy Keyword'],
            },
        };

        render(<Portal {...propsWithLegacyKeywords} />);

        await user.click(screen.getByTestId('keyword-change'));

        expect(setKeywordsMock).toHaveBeenCalledWith(['Updated Keyword']);
        expect(setFreeKeywordsMock).not.toHaveBeenCalled();
    });

    it('routes modern free keyword edits through setFreeKeywords', async () => {
        const user = userEvent.setup();
        const propsWithFreeKeywords = {
            ...defaultProps,
            filters: {
                ...defaultProps.filters,
                freeKeywords: ['Seismology'],
            },
        };

        render(<Portal {...propsWithFreeKeywords} />);

        await user.click(screen.getByTestId('keyword-change'));

        expect(setFreeKeywordsMock).toHaveBeenCalledWith(['Updated Keyword']);
        expect(setKeywordsMock).not.toHaveBeenCalled();
    });

    it('turns a suggestion into an exact keyword while clearing the text query atomically', async () => {
        const user = userEvent.setup();
        render(<Portal {...defaultProps} filters={{ ...defaultProps.filters, query: 'seis' }} />);

        await user.click(screen.getByTestId('keyword-select'));

        expect(setSearchAndKeywordsMock).toHaveBeenCalledWith('', ['Seismology'], false);
    });

    it('uses a filter drawer and mutually exclusive results/map views below 1280px', async () => {
        const user = userEvent.setup();
        isDesktopMock.mockReturnValue(false);
        render(<Portal {...defaultProps} />);

        expect(screen.queryByTestId('resizable-group')).not.toBeInTheDocument();
        expect(screen.getByTestId('portal-result-list')).toBeInTheDocument();
        expect(screen.queryByTestId('portal-map')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /map/i }));

        expect(screen.getByTestId('portal-map')).toBeInTheDocument();
        expect(screen.queryByTestId('portal-result-list')).not.toBeInTheDocument();
    });

    it('persists map collapsed state to localStorage', async () => {
        render(<Portal {...defaultProps} />);
        expect(localStorage.getItem('portal-map-collapsed')).toBe('false');
    });

    it('restores map collapsed state from localStorage', () => {
        localStorage.setItem('portal-map-collapsed', 'true');
        render(<Portal {...defaultProps} />);

        expect(screen.queryByTestId('resizable-handle')).toBeNull();
        expect(screen.getByRole('button', { name: /Show Map \(0\)/ })).toHaveAttribute('data-slot', 'button');
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
        render(<Portal {...defaultProps} />);
        fireEvent.click(screen.getAllByTestId('report-location-count')[0]);
        expect(screen.getByText(/2 locations/)).toBeInTheDocument();
    });

    it('starts with an empty count while map data loads asynchronously', () => {
        render(<Portal {...defaultProps} />);
        expect(screen.getByText(/0 locations/)).toBeInTheDocument();
    });

    describe('Geo Filter Integration', () => {
        it('starts worldwide in the sidebar and map', () => {
            render(<Portal {...defaultProps} />);
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');
            expect(screen.getByTestId('map-geo-enabled')).toHaveTextContent('true');
        });

        it('initializes geoFilterEnabled from URL bounds', () => {
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');
        });

        it('shows spatial filter badge when geo filter is enabled with bounds', () => {
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);
            expect(screen.getByText('Spatial filter')).toBeInTheDocument();
        });

        it('does not mark the worldwide default as a regional filter', () => {
            render(<Portal {...defaultProps} />);
            expect(screen.queryByText('Spatial filter')).not.toBeInTheDocument();
        });

        it('restores the opt-out from an older history entry and preserves it on pagination', async () => {
            usePageMock.mockReturnValue({ url: '/doi-search?map_filter=0#results', props: defaultProps });
            render(<Portal {...defaultProps} />);
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('false');
            await userEvent.setup().click(screen.getAllByTestId('next-page')[0]);
            expect(routerMock.get.mock.calls[0][0]).toBe('/doi-search?map_filter=0');
        });

        it('renders geo toggle button in filter panel', () => {
            render(<Portal {...defaultProps} />);
            expect(screen.getByTestId('geo-toggle')).toBeInTheDocument();
        });

        it('renders apply bounds button in filter panel', () => {
            render(<Portal {...defaultProps} />);
            expect(screen.getByTestId('apply-bounds')).toBeInTheDocument();
        });

        it('preserves bounds params on page change', async () => {
            const user = userEvent.setup();
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    query: '',
                    type: [] as string[],
                    keywords: [],
                    datacenter: [],
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                    temporal: null,
                },
            };
            render(<Portal {...propsWithBounds} />);

            const nextButtons = screen.getAllByTestId('next-page');
            await user.click(nextButtons[0]);

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('north=53.000000');
            expect(calledUrl).toContain('south=51.000000');
            expect(calledUrl).toContain('east=14.000000');
            expect(calledUrl).toContain('west=12.000000');
        });

        it('calls handleGeoFilterToggle when geo toggle is clicked (enable)', async () => {
            const user = userEvent.setup();
            const view = render(<Portal {...defaultProps} filters={{ ...defaultProps.filters, mapFilterEnabled: false }} />);
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('false');
            await user.click(screen.getByTestId('geo-toggle'));
            expect(setMapFilterEnabledMock).toHaveBeenCalledWith(true);
            view.rerender(<Portal {...defaultProps} />);
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');
        });

        it('calls handleGeoFilterToggle when geo toggle is clicked (disable)', async () => {
            const user = userEvent.setup();
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            const view = render(<Portal {...propsWithBounds} />);
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');
            await user.click(screen.getByTestId('geo-toggle'));
            expect(setMapFilterEnabledMock).toHaveBeenCalledWith(false);
            view.rerender(<Portal {...defaultProps} filters={{ ...defaultProps.filters, mapFilterEnabled: false }} />);
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('false');
        });

        it('calls handleBoundsChange when apply bounds is clicked', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            // Click the apply bounds button in mock PortalFilters
            await user.click(screen.getByTestId('apply-bounds'));

            // setBounds should have been called with the bounds from the mock
            expect(setBoundsMock).toHaveBeenCalledWith({ north: 53, south: 51, east: 14, west: 12 });
        });

        it('calls handleClearAllFilters when clear filters is clicked', async () => {
            const user = userEvent.setup();
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);

            // Initially geo filter is enabled
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');

            // Click clear all filters
            await user.click(screen.getByTestId('clear-filters'));

            expect(clearFiltersMock).toHaveBeenCalled();
            expect(screen.getByTestId('geo-filter-enabled')).toHaveTextContent('true');
        });

        it('calls handleViewportChange via PortalMap callback with debounce', () => {
            vi.useFakeTimers();

            // Enable geo filter via bounds in initial state
            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);

            // Trigger viewport change via the map mock
            const triggerButtons = screen.getAllByTestId('trigger-viewport-change');
            act(() => {
                triggerButtons[0].click();
            });

            // setBounds should NOT have been called yet (debounce)
            expect(setBoundsMock).not.toHaveBeenCalled();

            // Advance timer past the 500ms debounce
            act(() => {
                vi.advanceTimersByTime(600);
            });

            // Now setBounds should have been called
            expect(setBoundsMock).toHaveBeenCalledWith({ north: 54, south: 50, east: 15, west: 11 });

            vi.useRealTimers();
        });

        it('calls handleLayoutChanged when layout changes', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            const triggerButton = screen.getByTestId('trigger-layout-change');
            await user.click(triggerButton);

            // Layout should be persisted to localStorage
            const saved = localStorage.getItem('portal-panel-layout');
            expect(saved).toBe(JSON.stringify({ results: 60, map: 40 }));
        });

        it('resets debounce timer when viewport changes rapidly', () => {
            vi.useFakeTimers();

            const propsWithBounds = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    bounds: { north: 53, south: 51, east: 14, west: 12 },
                },
            };
            render(<Portal {...propsWithBounds} />);

            const triggerButtons = screen.getAllByTestId('trigger-viewport-change');

            // Fire first viewport change
            act(() => {
                triggerButtons[0].click();
            });

            // Advance only 200ms (less than 500ms debounce)
            act(() => {
                vi.advanceTimersByTime(200);
            });

            // Fire second viewport change (should reset the debounce timer)
            act(() => {
                triggerButtons[0].click();
            });

            // Advance 400ms (total 600ms from first, but only 400ms from second)
            act(() => {
                vi.advanceTimersByTime(400);
            });

            // setBounds should still not have been called (second fire reset the timer)
            expect(setBoundsMock).not.toHaveBeenCalled();

            // Advance another 200ms (now 600ms after second fire)
            act(() => {
                vi.advanceTimersByTime(200);
            });

            // Now it should have been called once
            expect(setBoundsMock).toHaveBeenCalledTimes(1);

            vi.useRealTimers();
        });

        it('calls handleBoundsChange with null to clear bounds', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            // Click the clear bounds button
            await user.click(screen.getByTestId('clear-bounds'));

            expect(setBoundsMock).toHaveBeenCalledWith(null);
        });

        it.each(['geo-toggle', 'clear-filters', 'popstate', 'navigation', 'unmount'])('cancels a pending pan on %s', (action) => {
            vi.useFakeTimers();
            try {
                const view = render(<Portal {...defaultProps} />);
                fireEvent.click(screen.getByTestId('trigger-viewport-change'));
                if (action === 'popstate') act(() => { window.dispatchEvent(new PopStateEvent('popstate')); });
                else if (action === 'navigation') act(() => routerMock.on.mock.lastCall![1]());
                else if (action === 'unmount') view.unmount();
                else fireEvent.click(screen.getByTestId(action));
                act(() => vi.advanceTimersByTime(500));
                expect(setBoundsMock).not.toHaveBeenCalled();
            } finally {
                vi.useRealTimers();
            }
        });

        it('skips list navigation for an unchanged viewport or a disabled filter', () => {
            vi.useFakeTimers();
            try {
                const bounds = { north: 54, south: 50, east: 15, west: 11 };
                const view = render(<Portal {...defaultProps} filters={{ ...defaultProps.filters, bounds }} />);
                fireEvent.click(screen.getByTestId('trigger-viewport-change'));
                act(() => vi.advanceTimersByTime(500));
                view.rerender(<Portal {...defaultProps} filters={{ ...defaultProps.filters, mapFilterEnabled: false }} />);
                fireEvent.click(screen.getByTestId('trigger-viewport-change'));
                act(() => vi.advanceTimersByTime(500));
                expect(setBoundsMock).not.toHaveBeenCalled();
            } finally {
                vi.useRealTimers();
            }
        });

        it('preserves keywords params on page change', async () => {
            const user = userEvent.setup();
            const propsWithKeywords = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    query: '',
                    type: [] as string[],
                    keywords: ['Seismology', 'Geology'],
                    datacenter: [],
                    bounds: null,
                    temporal: null,
                },
            };
            render(<Portal {...propsWithKeywords} />);

            const nextButtons = screen.getAllByTestId('next-page');
            await user.click(nextButtons[0]);

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('keywords');
        });
    });

    describe('Temporal Filter Integration', () => {
        it('passes temporalFilterEnabled to PortalFilters', () => {
            render(<Portal {...defaultProps} />);
            expect(screen.getByTestId('temporal-filter-enabled')).toHaveTextContent('false');
        });

        it('initializes temporalFilterEnabled from URL temporal', () => {
            const propsWithTemporal = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    temporal: { dateType: 'Created' as const, yearFrom: 2010, yearTo: 2020 },
                },
            };
            render(<Portal {...propsWithTemporal} />);
            expect(screen.getByTestId('temporal-filter-enabled')).toHaveTextContent('true');
        });

        it('handles temporal filter toggle enable', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            expect(screen.getByTestId('temporal-filter-enabled')).toHaveTextContent('false');
            await user.click(screen.getByTestId('temporal-toggle'));
            expect(screen.getByTestId('temporal-filter-enabled')).toHaveTextContent('true');
        });

        it('handles temporal filter toggle disable without duplicate navigation', async () => {
            const user = userEvent.setup();
            const propsWithTemporal = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    temporal: { dateType: 'Created' as const, yearFrom: 2010, yearTo: 2020 },
                },
            };
            render(<Portal {...propsWithTemporal} />);

            expect(screen.getByTestId('temporal-filter-enabled')).toHaveTextContent('true');
            await user.click(screen.getByTestId('temporal-toggle'));
            expect(screen.getByTestId('temporal-filter-enabled')).toHaveTextContent('false');
            // Parent toggle handler no longer calls setTemporal(null) -
            // the child component (PortalTemporalFilter) handles clearing
            // to avoid duplicate navigations.
            expect(setTemporalMock).not.toHaveBeenCalled();
        });

        it('calls setTemporal when apply temporal is clicked', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            await user.click(screen.getByTestId('apply-temporal'));
            expect(setTemporalMock).toHaveBeenCalledWith({ dateType: 'Created', yearFrom: 2010, yearTo: 2020 });
        });

        it('calls setTemporal(null) when clear temporal is clicked', async () => {
            const user = userEvent.setup();
            render(<Portal {...defaultProps} />);

            await user.click(screen.getByTestId('clear-temporal'));
            expect(setTemporalMock).toHaveBeenCalledWith(null);
        });

        it('resets temporalFilterEnabled on handleClearAllFilters', async () => {
            const user = userEvent.setup();
            const propsWithTemporal = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    temporal: { dateType: 'Created' as const, yearFrom: 2010, yearTo: 2020 },
                },
            };
            render(<Portal {...propsWithTemporal} />);

            expect(screen.getByTestId('temporal-filter-enabled')).toHaveTextContent('true');
            await user.click(screen.getByTestId('clear-filters'));
            expect(screen.getByTestId('temporal-filter-enabled')).toHaveTextContent('false');
            expect(clearFiltersMock).toHaveBeenCalled();
        });

        it('preserves temporal params on page change', async () => {
            const user = userEvent.setup();
            const propsWithTemporal = {
                ...defaultProps,
                filters: {
                    ...defaultProps.filters,
                    query: '',
                    type: [] as string[],
                    keywords: [],
                    datacenter: [],
                    bounds: null,
                    temporal: { dateType: 'Created' as const, yearFrom: 2010, yearTo: 2020 },
                },
            };
            render(<Portal {...propsWithTemporal} />);

            const nextButtons = screen.getAllByTestId('next-page');
            await user.click(nextButtons[0]);

            const calledUrl = routerMock.get.mock.calls[0][0] as string;
            expect(calledUrl).toContain('date_type=Created');
            expect(calledUrl).toContain('year_from=2010');
            expect(calledUrl).toContain('year_to=2020');
        });
    });
});

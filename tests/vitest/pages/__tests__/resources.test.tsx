import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { act, fireEvent, render, screen, waitFor, within } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ResourcesPage, { appendUniqueResources, mergeLoadMorePagination, mergeLoadMoreState } from '@/pages/resources';

const routerMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn() }));
const axiosGetMock = vi.hoisted(() => vi.fn());
const handleXmlFilesMock = vi.hoisted(() => vi.fn());
const buildCurationQueryFromResourceMock = vi.hoisted(() => vi.fn());
const editorRouteMock = vi.hoisted(() =>
    vi.fn(({ query }: { query?: Record<string, string | number> } = {}) => ({
        url: query ? `/editor?${new URLSearchParams(Object.entries(query).map(([key, value]) => [key, String(value)])).toString()}` : '/editor',
        method: 'get',
    })),
);
const openDetachedTabMock = vi.hoisted(() => vi.fn());
const toastSuccessMock = vi.hoisted(() => vi.fn());
const toastErrorMock = vi.hoisted(() => vi.fn());
const authUserMock = vi.hoisted(() => ({
    id: 1,
    name: 'Test User',
    email: 'test@example.test',
    font_size_preference: 'regular',
    email_verified_at: null,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
    role: 'group_leader',
    can_manage_landing_pages: true,
    can_register_doi: true,
    can_register_production_doi: true,
    can_send_review_links: true,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
    usePage: () => ({
        props: {
            auth: {
                user: authUserMock,
            },
        },
    }),
}));

vi.mock('axios', () => ({
    default: { get: axiosGetMock },
    get: axiosGetMock,
    isAxiosError: (err: unknown) => Boolean(err && typeof err === 'object' && 'isAxiosError' in err),
}));

vi.mock('@/lib/curation-query', () => ({
    buildCurationQueryFromResource: buildCurationQueryFromResourceMock,
}));
vi.mock('@/lib/datacite-upload', () => ({ handleXmlFiles: handleXmlFilesMock }));

vi.mock('@/routes', () => ({
    editor: editorRouteMock,
}));
vi.mock('@/lib/detached-tab', () => ({ openDetachedTab: openDetachedTabMock }));
vi.mock('sonner', () => ({
    toast: {
        success: toastSuccessMock,
        error: toastErrorMock,
    },
}));

vi.mock('@/utils/filter-parser', () => ({
    parseResourceFiltersFromUrl: vi.fn().mockReturnValue({}),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('@/components/resources-filters', () => ({
    ResourcesFilters: ({
        filters,
        onFilterChange,
        totalCount,
        countStatus,
        onRetryCount,
        isLoading,
    }: {
        filters: { datacenter_id?: number; without_datacenter?: boolean; without_spdx_license?: boolean; search?: string };
        onFilterChange: (filters: Record<string, unknown>) => void;
        totalCount: number | null;
        countStatus?: string;
        onRetryCount?: () => void;
        isLoading: boolean;
    }) => (
        <div data-testid="resources-filters">
            <span data-testid="resource-filter-state">{JSON.stringify(filters)}</span>
            <span data-testid="resource-total-count">{totalCount ?? 'pending'}</span>
            <span data-testid="resource-count-status">{countStatus}</span>
            {countStatus === 'failed' && <button onClick={onRetryCount}>Retry count</button>}
            <span data-testid="resource-loading-state">{String(isLoading)}</span>
            <button type="button" data-testid="select-datacenter" onClick={() => onFilterChange({ ...filters, datacenter_id: 7 })}>
                Select datacenter
            </button>
            <button type="button" data-testid="select-without-datacenter" onClick={() => onFilterChange({ without_datacenter: true })}>
                Select without datacenter
            </button>
            <button
                type="button"
                data-testid="select-without-spdx-license"
                onClick={() => onFilterChange({ ...filters, without_spdx_license: true })}
            >
                Select without SPDX license
            </button>
            <button type="button" data-testid="clear-datacenter" onClick={() => onFilterChange({ search: filters.search })}>
                Clear datacenter
            </button>
        </div>
    ),
}));
vi.mock('@/components/landing-pages/modals/SetupLandingPageModal', () => ({
    default: ({ isOpen, context }: { isOpen: boolean; context: string }) => (isOpen ? <div data-testid="setup-lp-context">{context}</div> : null),
}));
vi.mock('@/components/resources/modals/ImportFromDataCiteModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/ImportSingleOldResourceModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/RegisterDoiModal', () => ({ default: () => null }));
vi.mock('@/components/citations/CitationManagerModal', () => ({ CitationManagerModal: () => null }));
vi.mock('@/components/datacite-url-update-modal', () => ({
    DataCiteUrlUpdateModal: ({ open, scope }: { open: boolean; scope: string }) =>
        open ? <div data-testid="datacite-url-update-modal-mock">{scope}</div> : null,
}));
vi.mock('@/components/ui/validation-error-modal', () => ({ ValidationErrorModal: () => null }));
vi.mock('@/hooks/use-citation-vocabularies', () => ({
    useCitationVocabularies: () => ({
        vocabularies: { resourceTypes: [], relationTypes: [], contributorTypes: [] },
        isLoading: false,
    }),
}));

const openResourceActionsMenu = async () => {
    await userEvent.click(screen.getByTestId('resources-actions-menu-trigger'));
};

const QUICK_RESOURCE_ACTION_TEST_IDS = new Set(['resources-action-edit', 'resources-action-setup-landing-page']);

const clickResourceAction = async (testId: string) => {
    if (!QUICK_RESOURCE_ACTION_TEST_IDS.has(testId)) {
        await openResourceActionsMenu();
    }

    await userEvent.click(screen.getByTestId(testId));
};

describe('ResourcesPage', () => {
    let originalOpen: typeof window.open;
    let originalClipboardDescriptor: PropertyDescriptor | undefined;
    let openMock: ReturnType<typeof vi.fn>;
    let clipboardWriteTextMock: ReturnType<typeof vi.fn>;
    let intersectionCallback: IntersectionObserverCallback | null;

    beforeEach(() => {
        authUserMock.can_send_review_links = true;
        routerMock.get.mockClear();
        routerMock.delete.mockClear();
        routerMock.reload.mockClear();
        routerMock.visit.mockClear();
        axiosGetMock.mockReset();
        handleXmlFilesMock.mockReset();
        axiosGetMock.mockResolvedValue({ data: {} });
        localStorage.clear();
        window.history.replaceState({}, '', '/resources');
        buildCurationQueryFromResourceMock.mockReset();
        buildCurationQueryFromResourceMock.mockResolvedValue({});
        editorRouteMock.mockClear();
        openDetachedTabMock.mockReset();
        openDetachedTabMock.mockReturnValue({} as Window);
        toastSuccessMock.mockReset();
        toastErrorMock.mockReset();
        originalOpen = window.open;
        originalClipboardDescriptor = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
        openMock = vi.fn().mockReturnValue({ closed: false });
        clipboardWriteTextMock = vi.fn().mockResolvedValue(undefined);
        intersectionCallback = null;
        Object.defineProperty(window, 'open', {
            value: openMock,
            configurable: true,
            writable: true,
        });
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: clipboardWriteTextMock },
            configurable: true,
            writable: true,
        });

        global.IntersectionObserver = vi.fn().mockImplementation(function (callback: IntersectionObserverCallback) {
            intersectionCallback = callback;

            return {
                observe: vi.fn(),
                unobserve: vi.fn(),
                disconnect: vi.fn(),
                root: null,
                rootMargin: '',
                thresholds: [],
                takeRecords: vi.fn(() => []),
            };
        }) as unknown as typeof IntersectionObserver;
    });

    describe('cursor recovery', () => {
        const listProps: Parameters<typeof ResourcesPage>[0] = {
            resources: [{ id: 51, title: 'Existing resource', publicstatus: 'draft' } as never],
            pagination: { per_page: 50, total: 51, from: 1, to: 50, has_more: true, next_cursor: 'server-cursor', count_status: 'ready' },
            sort: { key: 'updated_at', direction: 'desc' },
            filters: {},
        };
        const loadMoreCalls = () => axiosGetMock.mock.calls.filter(([url]) => url === '/resources/load-more');
        const intersect = async () => {
            await act(async () => {
                intersectionCallback?.([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver);
            });
        };

        it('restores a saved sort through navigation before using a cursor', async () => {
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'desc' }));
            render(<ResourcesPage {...listProps} />);
            await waitFor(() => expect(routerMock.visit).toHaveBeenCalledTimes(1));
            expect(routerMock.visit).toHaveBeenCalledWith('/resources?sort_key=id&sort_direction=desc&per_page=50', expect.any(Object));
            await intersect();
            expect(loadMoreCalls()).toHaveLength(0);
            expect(screen.getByText('Existing resource')).toBeInTheDocument();
        });

        it('honors explicit URL sorting over the saved preference', async () => {
            window.history.replaceState({}, '', '/resources?sort_key=updated_at&sort_direction=desc');
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'desc' }));
            render(<ResourcesPage {...listProps} />);
            await intersect();
            expect(routerMock.visit).not.toHaveBeenCalled();
            expect(loadMoreCalls()[0]?.[1].params.get('sort_key')).toBe('updated_at');
            expect(loadMoreCalls()[0]?.[1].params.get('cursor')).toBe('server-cursor');
        });

        it.each(['cursor', 'network'])('pauses automatic requests after a %s error', async (kind) => {
            const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
            axiosGetMock.mockImplementation((url: string) =>
                url === '/resources/load-more'
                    ? Promise.reject({
                          isAxiosError: true,
                          message: 'Request failed',
                          response: kind === 'cursor' ? { status: 422, data: { errors: { cursor: ['Invalid cursor'] } } } : undefined,
                      })
                    : Promise.resolve({ data: { datacenters: [] } }),
            );
            try {
                render(<ResourcesPage {...listProps} />);
                await intersect();
                await waitFor(() => expect(toastErrorMock).toHaveBeenCalledTimes(1));
                await intersect();
                await intersect();
                expect(loadMoreCalls()).toHaveLength(1);
                expect(screen.getByText('Existing resource')).toBeInTheDocument();
                expect(screen.getByRole('button', { name: kind === 'cursor' ? 'Reload list' : 'Retry' })).toBeInTheDocument();
            } finally {
                consoleError.mockRestore();
            }
        });
        it.each(['sort_key=updated_at', 'sort_direction=desc', 'cursor=explicit-cursor'])(
            'keeps the server context for an explicit %s',
            async (query) => {
                window.history.replaceState({}, '', `/resources?${query}`);
                localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'asc' }));
                render(<ResourcesPage {...listProps} />);
                await intersect();
                expect(routerMock.visit).not.toHaveBeenCalled();
                expect(loadMoreCalls()[0]?.[1].params.get('sort_key')).toBe('updated_at');
                expect(localStorage.getItem('resources.sort-preference')).toBe(JSON.stringify(listProps.sort));
            },
        );

        it.each([null, '{broken', '{"key":"invalid","direction":"asc"}', '{"key":"id","direction":"invalid"}', JSON.stringify(listProps.sort)])(
            'does not navigate for missing, invalid or matching storage: %s',
            async (stored) => {
                if (stored !== null) localStorage.setItem('resources.sort-preference', stored);
                render(<ResourcesPage {...listProps} />);
                await intersect();
                expect(routerMock.visit).not.toHaveBeenCalled();
                expect(loadMoreCalls()).toHaveLength(1);
            },
        );

        it('keeps loading usable when browser storage throws', async () => {
            const read = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
                throw new Error('Unavailable');
            });
            const write = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
                throw new Error('Unavailable');
            });
            try {
                render(<ResourcesPage {...listProps} />);
                await intersect();
                expect(routerMock.visit).not.toHaveBeenCalled();
                expect(loadMoreCalls()).toHaveLength(1);
            } finally {
                read.mockRestore();
                write.mockRestore();
            }
        });

        it('preserves explicit filters and page size when restoring sorting', async () => {
            window.history.replaceState({}, '', '/resources?search=climate&per_page=25&without_spdx_license=1');
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'title', direction: 'asc' }));
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'without_datacenter' }));
            render(
                <ResourcesPage
                    {...listProps}
                    pagination={{ ...listProps.pagination, per_page: 25 }}
                    filters={{ search: 'climate', without_spdx_license: true }}
                />,
            );
            await waitFor(() => expect(routerMock.visit).toHaveBeenCalledTimes(1));
            const url = new URL(routerMock.visit.mock.calls[0][0], 'https://example.test');
            expect(Object.fromEntries(url.searchParams)).toEqual({
                sort_key: 'title',
                sort_direction: 'asc',
                per_page: '25',
                search: 'climate',
                without_spdx_license: '1',
            });
        });

        it('combines saved sorting and datacenter into one visit and pauses while validating the datacenter', async () => {
            let resolveOptions!: (data: unknown) => void;
            axiosGetMock.mockImplementation(
                () =>
                    new Promise((resolve) => {
                        resolveOptions = resolve;
                    }),
            );
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'desc' }));
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 7 }));
            render(<ResourcesPage {...listProps} />);
            await intersect();
            expect(loadMoreCalls()).toHaveLength(0);
            expect(routerMock.visit).not.toHaveBeenCalled();
            expect(localStorage.getItem('resources.sort-preference')).toBe('{"key":"id","direction":"desc"}');
            await act(async () => resolveOptions({ data: { datacenters: [{ id: 7, name: 'GFZ' }] } }));
            expect(routerMock.visit).toHaveBeenCalledTimes(1);
            expect(routerMock.visit).toHaveBeenCalledWith(
                '/resources?sort_key=id&sort_direction=desc&per_page=50&datacenter_id=7',
                expect.any(Object),
            );
            await intersect();
            expect(loadMoreCalls()).toHaveLength(0);
        });

        it('restores sorting even when datacenter options fail', async () => {
            const log = vi.spyOn(console, 'error').mockImplementation(() => {});
            axiosGetMock.mockRejectedValue(new Error('Options unavailable'));
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'desc' }));
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 7 }));
            try {
                render(<ResourcesPage {...listProps} />);
                await waitFor(() => expect(routerMock.visit).toHaveBeenCalledTimes(1));
                expect(routerMock.visit).toHaveBeenCalledWith('/resources?sort_key=id&sort_direction=desc&per_page=50', expect.any(Object));
            } finally {
                log.mockRestore();
            }
        });

        it('does not let late filter options override a user navigation', async () => {
            let resolveOptions!: (data: unknown) => void;
            axiosGetMock.mockImplementation(
                () =>
                    new Promise((resolve) => {
                        resolveOptions = resolve;
                    }),
            );
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'desc' }));
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 7 }));
            render(<ResourcesPage {...listProps} />);
            fireEvent.click(screen.getByTestId('select-without-datacenter'));
            await act(async () => resolveOptions({ data: { datacenters: [{ id: 7, name: 'GFZ' }] } }));
            expect(routerMock.visit).toHaveBeenCalledTimes(1);
            expect(routerMock.visit).toHaveBeenCalledWith(
                '/resources?sort_key=updated_at&sort_direction=desc&per_page=50&without_datacenter=1',
                expect.any(Object),
            );
        });

        it('loads only the new cursor after the restored server response arrives', async () => {
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'desc' }));
            const { rerender } = render(<ResourcesPage {...listProps} />);
            const visit = routerMock.visit.mock.calls[0][1];
            await intersect();
            expect(loadMoreCalls()).toHaveLength(0);
            window.history.replaceState({}, '', '/resources?sort_key=id&sort_direction=desc&per_page=50');
            rerender(
                <ResourcesPage
                    {...listProps}
                    sort={{ key: 'id', direction: 'desc' }}
                    pagination={{ ...listProps.pagination, next_cursor: 'restored-cursor' }}
                />,
            );
            act(() => {
                visit.onSuccess();
                visit.onFinish();
            });
            await intersect();
            expect(loadMoreCalls()).toHaveLength(1);
            expect(loadMoreCalls()[0][1].params.get('cursor')).toBe('restored-cursor');
            expect(loadMoreCalls()[0][1].params.get('sort_key')).toBe('id');
        });

        it('allows one manual retry at a time, stays paused on failure and resumes after success', async () => {
            const log = vi.spyOn(console, 'error').mockImplementation(() => {});
            let attempt = 0;
            let resolveRetry!: (data: unknown) => void;
            axiosGetMock.mockImplementation((url: string) => {
                if (url !== '/resources/load-more') return Promise.resolve({ data: {} });
                attempt++;
                if (attempt < 3) return Promise.reject({ isAxiosError: true, message: 'Offline' });
                return new Promise((resolve) => {
                    resolveRetry = resolve;
                });
            });
            try {
                render(<ResourcesPage {...listProps} />);
                await intersect();
                await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Retry' })));
                await intersect();
                expect(loadMoreCalls()).toHaveLength(2);
                await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Retry' })));
                await intersect();
                expect(loadMoreCalls()).toHaveLength(3);
                expect(loadMoreCalls().every(([, options]) => options.params.get('cursor') === 'server-cursor')).toBe(true);
                await act(async () =>
                    resolveRetry({
                        data: {
                            resources: [{ id: 52, title: 'Next row', publicstatus: 'draft' }],
                            pagination: { has_more: true, next_cursor: 'next-cursor' },
                        },
                    }),
                );
                expect(screen.getByText('Next row')).toBeInTheDocument();
                expect(screen.queryByRole('button', { name: 'Retry' })).not.toBeInTheDocument();
                await intersect();
                expect(loadMoreCalls()).toHaveLength(4);
                expect(loadMoreCalls()[3][1].params.get('cursor')).toBe('next-cursor');
                expect(toastErrorMock).toHaveBeenCalledTimes(2);
            } finally {
                log.mockRestore();
            }
        });

        it('reloads an invalid cursor with its filters and replaces the list only on success', async () => {
            const log = vi.spyOn(console, 'error').mockImplementation(() => {});
            axiosGetMock.mockImplementation((url: string) =>
                url === '/resources/load-more'
                    ? Promise.reject({ isAxiosError: true, response: { status: 422, data: { errors: { cursor: ['Expired'] } } } })
                    : Promise.resolve({ data: {} }),
            );
            try {
                const props = {
                    ...listProps,
                    pagination: { ...listProps.pagination, per_page: 25 },
                    filters: { search: 'climate', without_spdx_license: true },
                };
                const { rerender } = render(<ResourcesPage {...props} />);
                fireEvent.click(screen.getByTestId('resources-row-checkbox-51'));
                await intersect();
                fireEvent.click(screen.getByRole('button', { name: 'Reload list' }));
                const [url, visit] = routerMock.visit.mock.calls[0];
                expect(Object.fromEntries(new URL(url, 'https://example.test').searchParams)).toEqual({
                    sort_key: 'updated_at',
                    sort_direction: 'desc',
                    per_page: '25',
                    search: 'climate',
                    without_spdx_license: '1',
                });
                expect(screen.getByText('Existing resource')).toBeInTheDocument();
                expect(screen.getByRole('button', { name: 'Reload list' })).toBeDisabled();
                await intersect();
                expect(loadMoreCalls()).toHaveLength(1);
                act(() => visit.onFinish());
                expect(screen.getByRole('button', { name: 'Reload list' })).toBeEnabled();
                await intersect();
                expect(loadMoreCalls()).toHaveLength(1);
                fireEvent.click(screen.getByRole('button', { name: 'Reload list' }));
                const retryVisit = routerMock.visit.mock.calls[1][1];
                rerender(
                    <ResourcesPage
                        {...props}
                        resources={[{ id: 9, title: 'Fresh first page', publicstatus: 'draft' } as never]}
                        pagination={{ ...props.pagination, next_cursor: null, has_more: false }}
                    />,
                );
                act(() => {
                    retryVisit.onSuccess();
                    retryVisit.onFinish();
                });
                expect(screen.queryByText('Existing resource')).not.toBeInTheDocument();
                expect(screen.getByText('Fresh first page')).toBeInTheDocument();
                expect(screen.queryByRole('button', { name: 'Reload list' })).not.toBeInTheDocument();
                expect(screen.getByText(/Select rows to enable resource actions/)).toBeInTheDocument();
            } finally {
                log.mockRestore();
            }
        });

        it.each([
            { isAxiosError: true, response: { status: 422, data: { message: 'Invalid filters', errors: { search: ['Too long'] } } } },
            { isAxiosError: true, response: { status: 422, data: { errors: { cursor: [] } } }, message: 'Invalid request' },
            new Error('Unexpected failure'),
        ])('does not classify other errors as cursor errors: %j', async (error) => {
            const log = vi.spyOn(console, 'error').mockImplementation(() => {});
            axiosGetMock.mockImplementation((url: string) =>
                url === '/resources/load-more' ? Promise.reject(error) : Promise.resolve({ data: {} }),
            );
            try {
                render(<ResourcesPage {...listProps} />);
                await intersect();
                expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
                expect(screen.queryByRole('button', { name: 'Reload list' })).not.toBeInTheDocument();
                await intersect();
                expect(loadMoreCalls()).toHaveLength(1);
            } finally {
                log.mockRestore();
            }
        });

        it('offers a reload instead of loading the first page when the next cursor is missing', async () => {
            render(<ResourcesPage {...listProps} pagination={{ ...listProps.pagination, next_cursor: null }} />);
            await intersect();
            expect(loadMoreCalls()).toHaveLength(0);
            expect(screen.getByRole('button', { name: 'Reload list' })).toBeInTheDocument();
        });

        it.each(['sort', 'filter', 'history'])('discards old responses and observer callbacks across a %s change', async (change) => {
            let resolveOld!: (data: unknown) => void;
            axiosGetMock.mockImplementation((url: string) =>
                url === '/resources/load-more'
                    ? new Promise((resolve) => {
                          resolveOld = resolve;
                      })
                    : Promise.resolve({ data: {} }),
            );
            const { rerender } = render(<ResourcesPage {...listProps} />);
            await intersect();
            const oldCallback = intersectionCallback;
            const signal = loadMoreCalls()[0][1].signal as AbortSignal;
            if (change === 'sort') fireEvent.click(screen.getByRole('button', { name: /sort by the resource title/i }));
            if (change === 'filter') fireEvent.click(screen.getByTestId('select-without-datacenter'));
            if (change === 'history') {
                rerender(
                    <ResourcesPage
                        {...listProps}
                        sort={{ key: 'title', direction: 'asc' }}
                        resources={[{ id: 99, title: 'History page', publicstatus: 'draft' } as never]}
                        pagination={{ ...listProps.pagination, next_cursor: 'history-cursor' }}
                    />,
                );
            }
            expect(signal.aborted).toBe(true);
            await act(async () => {
                oldCallback?.([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver);
                resolveOld({
                    data: {
                        resources: [{ id: 88, title: 'Stale response', publicstatus: 'draft' }],
                        pagination: { has_more: false, next_cursor: null },
                    },
                });
            });
            expect(loadMoreCalls()).toHaveLength(1);
            expect(screen.queryByText('Stale response')).not.toBeInTheDocument();
            expect(toastErrorMock).not.toHaveBeenCalled();
        });

        it('ignores completion of superseded visits', () => {
            render(<ResourcesPage {...listProps} />);
            fireEvent.click(screen.getByTestId('select-without-datacenter'));
            const oldVisit = routerMock.visit.mock.calls[0][1];
            fireEvent.click(screen.getByRole('button', { name: /sort by the resource title/i }));
            act(() => {
                oldVisit.onSuccess();
                oldVisit.onFinish();
            });
            expect(screen.queryByRole('button', { name: 'Reload list' })).not.toBeInTheDocument();
            act(() => routerMock.visit.mock.calls[1][1].onFinish());
            expect(screen.getByRole('button', { name: 'Reload list' })).toBeEnabled();
        });

        it('recovers from a synchronous navigation failure', async () => {
            routerMock.visit.mockImplementationOnce(() => {
                throw new Error('Navigation unavailable');
            });
            render(<ResourcesPage {...listProps} />);
            fireEvent.click(screen.getByTestId('select-without-datacenter'));
            expect(screen.getByRole('button', { name: 'Reload list' })).toBeEnabled();
            await intersect();
            expect(loadMoreCalls()).toHaveLength(0);
        });
        it('ignores a queued observer callback from the previous cursor slice', async () => {
            axiosGetMock.mockImplementation((url: string) =>
                Promise.resolve({
                    data:
                        url === '/resources/load-more'
                            ? {
                                  resources: [{ id: 52, title: 'Next row', publicstatus: 'draft' }],
                                  pagination: { has_more: true, next_cursor: 'next-cursor' },
                              }
                            : {},
                }),
            );
            render(<ResourcesPage {...listProps} />);
            const oldCallback = intersectionCallback;
            await intersect();
            await act(async () => oldCallback?.([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver));
            expect(loadMoreCalls()).toHaveLength(1);
            await intersect();
            expect(loadMoreCalls()).toHaveLength(2);
            expect(loadMoreCalls()[1][1].params.get('cursor')).toBe('next-cursor');
        });

        it('aborts an in-flight cursor request on unmount without reporting an error', async () => {
            let rejectOld!: (error: unknown) => void;
            axiosGetMock.mockImplementation((url: string) =>
                url === '/resources/load-more'
                    ? new Promise((_resolve, reject) => {
                          rejectOld = reject;
                      })
                    : Promise.resolve({ data: {} }),
            );
            const { unmount } = render(<ResourcesPage {...listProps} />);
            await intersect();
            const signal = loadMoreCalls()[0][1].signal as AbortSignal;
            unmount();
            expect(signal.aborted).toBe(true);
            await act(async () => rejectOld(new Error('Aborted response')));
            expect(toastErrorMock).not.toHaveBeenCalled();
        });

        it('restores both sorting and the without-datacenter preference without waiting for options', () => {
            axiosGetMock.mockImplementation(() => new Promise(() => {}));
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'desc' }));
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'without_datacenter' }));
            render(<ResourcesPage {...listProps} />);
            expect(routerMock.visit).toHaveBeenCalledTimes(1);
            expect(routerMock.visit).toHaveBeenCalledWith(
                '/resources?sort_key=id&sort_direction=desc&per_page=50&without_datacenter=1',
                expect.any(Object),
            );
        });
    });

    it('lets a curator upload XML from the list and opens the created draft', async () => {
        handleXmlFilesMock.mockResolvedValue({ resourceId: '42', editorUrl: '/editor?resourceId=42' });
        const props = {
            resources: [],
            pagination: { per_page: 25, total: 0, from: null, to: null, has_more: false },
            sort: { key: 'id' as const, direction: 'desc' as const },
            canCreateResource: true,
            canImportFromDataCite: false,
        };

        render(<ResourcesPage {...props} />);
        expect(screen.getByTestId('resources-xml-upload')).toBeInTheDocument();
        expect(screen.queryByText('Import all old Resources')).not.toBeInTheDocument();

        const file = new File(['<resource/>'], 'dataset.xml', { type: 'application/xml' });
        fireEvent.change(screen.getByTestId('resources-xml-upload-input'), { target: { files: [file] } });

        await waitFor(() => expect(handleXmlFilesMock).toHaveBeenCalledWith([file]));
        await waitFor(() => expect(routerMock.visit).toHaveBeenCalledWith('/editor?resourceId=42'));
    });

    it('keeps the list open when XML upload fails or returns no draft', async () => {
        handleXmlFilesMock
            .mockRejectedValueOnce(new Error('Duplicate DOI'))
            .mockResolvedValueOnce({ resourceId: null, editorUrl: '/editor?xmlSession=old' });
        render(
            <ResourcesPage
                resources={[]}
                pagination={{ per_page: 25, total: 0, from: null, to: null, has_more: false }}
                sort={{ key: 'id', direction: 'desc' }}
                canCreateResource
            />,
        );
        const input = screen.getByTestId('resources-xml-upload-input');
        fireEvent.change(input, { target: { files: [new File(['x'], 'first.xml')] } });
        await waitFor(() => expect(toastErrorMock).toHaveBeenCalledWith('Duplicate DOI'));
        fireEvent.change(input, { target: { files: [new File(['x'], 'second.xml')] } });
        await waitFor(() => expect(toastErrorMock).toHaveBeenCalledWith('The XML upload did not return a draft resource.'));
        expect(routerMock.visit).not.toHaveBeenCalled();
    });

    it('retains asynchronous count state while advancing a cursor', () => {
        expect(
            mergeLoadMorePagination(
                {
                    per_page: 20,
                    total: 73,
                    from: 1,
                    to: 20,
                    has_more: true,
                    next_cursor: 'first-cursor',
                    previous_cursor: null,
                    count_status: 'ready',
                    filter_fingerprint: 'fingerprint',
                },
                {
                    per_page: 20,
                    has_more: true,
                    next_cursor: 'second-cursor',
                },
                20,
            ),
        ).toEqual({
            per_page: 20,
            total: 73,
            from: 1,
            to: 40,
            has_more: true,
            next_cursor: 'second-cursor',
            previous_cursor: null,
            count_status: 'ready',
            filter_fingerprint: 'fingerprint',
        });
    });

    it('deduplicates resources when adjacent cursor slices overlap', () => {
        const current = [{ id: 1 }, { id: 2 }] as Parameters<typeof appendUniqueResources>[0];
        const incoming = [{ id: 2 }, { id: 3 }] as Parameters<typeof appendUniqueResources>[1];
        const merged = appendUniqueResources(current, incoming);

        expect(merged.map((resource) => resource.id)).toEqual([1, 2, 3]);
        expect(
            mergeLoadMorePagination(
                { per_page: 2, total: 3, from: 1, to: 2, has_more: true },
                { per_page: 2, has_more: false },
                merged.length - current.length,
            ).to,
        ).toBe(3);
    });

    it('merges a load-more response into the latest listing state', () => {
        const current = {
            resources: [{ id: 1 }, { id: 3 }],
            pagination: { per_page: 2, total: 3, from: 1, to: 2, has_more: true, next_cursor: 'newer-cursor' },
        } as Parameters<typeof mergeLoadMoreState>[0];
        const incoming = [{ id: 2 }, { id: 3 }] as Parameters<typeof mergeLoadMoreState>[1];

        const merged = mergeLoadMoreState(current, incoming, {
            per_page: 2,
            has_more: false,
            next_cursor: null,
        });

        expect(merged.resources.map((resource) => resource.id)).toEqual([1, 3, 2]);
        expect(merged.pagination).toMatchObject({
            total: 3,
            to: 3,
            has_more: false,
            next_cursor: null,
        });
    });

    it('does not overwrite newer resources when an in-flight load-more response resolves', async () => {
        let resolveLoadMore: ((value: { data: { resources: unknown[]; pagination: Record<string, unknown> } }) => void) | undefined;
        axiosGetMock.mockImplementation((url: string) => {
            if (url === '/resources/filter-options') {
                return Promise.resolve({ data: { datacenters: [] } });
            }

            if (url === '/resources/load-more') {
                return new Promise((resolve) => {
                    resolveLoadMore = resolve;
                });
            }

            return Promise.resolve({ data: {} });
        });

        const firstResource = { id: 1, title: 'First resource', publicstatus: 'draft' } as never;
        const newerResource = { id: 3, title: 'Newer resource', publicstatus: 'draft' } as never;
        const sort = { key: 'id' as const, direction: 'asc' as const };
        const filters = {};
        const { rerender } = render(
            <ResourcesPage
                resources={[firstResource]}
                pagination={{
                    per_page: 2,
                    total: 3,
                    from: 1,
                    to: 1,
                    has_more: true,
                    next_cursor: 'first-cursor',
                    count_status: 'ready',
                }}
                sort={sort}
                filters={filters}
            />,
        );

        await waitFor(() => expect(intersectionCallback).not.toBeNull());
        act(() => {
            intersectionCallback?.([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver);
        });
        await waitFor(() => expect(axiosGetMock).toHaveBeenCalledWith('/resources/load-more', expect.anything()));

        rerender(
            <ResourcesPage
                resources={[firstResource, newerResource]}
                pagination={{
                    per_page: 2,
                    total: 3,
                    from: 1,
                    to: 2,
                    has_more: true,
                    next_cursor: 'newer-cursor',
                    count_status: 'ready',
                }}
                sort={sort}
                filters={filters}
            />,
        );
        await waitFor(() => expect(screen.getByTestId('resources-row-checkbox-3')).toBeInTheDocument());

        await act(async () => {
            resolveLoadMore?.({
                data: {
                    resources: [{ id: 2, title: 'Loaded resource', publicstatus: 'draft' }],
                    pagination: { per_page: 2, has_more: false, next_cursor: null },
                },
            });
        });

        await waitFor(() => expect(screen.getByTestId('resources-row-checkbox-2')).toBeInTheDocument());
        expect(screen.getByTestId('resources-row-checkbox-1')).toBeInTheDocument();
        expect(screen.getByTestId('resources-row-checkbox-3')).toBeInTheDocument();
    });

    it('loads the exact count asynchronously and accepts only the matching fingerprint', async () => {
        axiosGetMock
            .mockResolvedValueOnce({ data: { datacenters: [] } })
            .mockResolvedValueOnce({ data: { filter_fingerprint: 'matching', total: 42, count_status: 'ready' } });

        render(
            <ResourcesPage
                resources={[]}
                pagination={{
                    per_page: 50,
                    total: null,
                    from: null,
                    to: null,
                    has_more: false,
                    next_cursor: null,
                    previous_cursor: null,
                    count_status: 'pending',
                    filter_fingerprint: 'matching',
                }}
                sort={{ key: 'updated_at', direction: 'desc' }}
                filters={{ search: 'climate' }}
            />,
        );

        expect(screen.getByTestId('resource-total-count')).toHaveTextContent('pending');
        await waitFor(() => expect(screen.getByTestId('resource-total-count')).toHaveTextContent('42'));
        expect(screen.getByTestId('resource-count-status')).toHaveTextContent('ready');
    });

    describe('asynchronous counts after partial reloads', () => {
        const props: Parameters<typeof ResourcesPage>[0] = {
            resources: [{ id: 1, title: 'Original resource', publicstatus: 'draft' } as never],
            pagination: {
                per_page: 50,
                total: null,
                from: 1,
                to: 1,
                has_more: false,
                count_status: 'pending',
                filter_fingerprint: 'matching',
            },
            sort: { key: 'updated_at', direction: 'desc' },
            filters: { search: 'climate' },
        };
        let requests: {
            signal: AbortSignal;
            params: URLSearchParams;
            resolve: (total: number) => void;
            reject: (error: unknown) => void;
        }[];

        beforeEach(() => {
            requests = [];
            axiosGetMock.mockImplementation((url: string, config: { signal: AbortSignal; params: URLSearchParams }) => {
                if (url !== '/resources/count') return Promise.resolve({ data: { datacenters: [] } });

                return new Promise((resolve, reject) => {
                    requests.push({
                        ...config,
                        resolve: (total) => resolve({ data: { filter_fingerprint: 'matching', total, count_status: 'ready' } }),
                        reject,
                    });
                });
            });
        });

        it.each(['resources', 'pagination'] as const)('refreshes a completed count after a %s-only reload', async (prop) => {
            const { rerender } = render(<ResourcesPage {...props} />);
            await act(async () => requests[0].resolve(42));
            expect(screen.getByTestId('resource-total-count')).toHaveTextContent('42');
            expect(screen.getByTestId('resource-count-status')).toHaveTextContent('ready');

            // Inertia preserves the other prop references during a partial reload.
            const refreshedProps = prop === 'resources' ? { resources: [...props.resources] } : { pagination: { ...props.pagination } };
            rerender(<ResourcesPage {...props} {...refreshedProps} />);

            expect(screen.getByTestId('resource-count-status')).toHaveTextContent('pending');
            expect(requests).toHaveLength(2);
            expect(requests[1].params.toString()).toBe('per_page=50&sort_key=updated_at&sort_direction=desc&search=climate');
            await act(async () => requests[1].resolve(43));
            expect(screen.getByTestId('resource-total-count')).toHaveTextContent('43');
            expect(screen.getByTestId('resource-count-status')).toHaveTextContent('ready');

            rerender(<ResourcesPage {...props} {...refreshedProps} />);
            expect(requests).toHaveLength(2);
            expect(screen.getByTestId('resource-total-count')).toHaveTextContent('43');
        });

        it.each(['success', 'failure'] as const)('ignores a superseded count %s with the same fingerprint', async (outcome) => {
            const { rerender } = render(<ResourcesPage {...props} />);
            rerender(<ResourcesPage {...props} resources={[...props.resources]} />);

            expect(requests[0].signal.aborted).toBe(true);
            expect(requests).toHaveLength(2);
            await act(async () => requests[1].resolve(43));

            await act(async () => {
                if (outcome === 'success') requests[0].resolve(42);
                else requests[0].reject(new Error('Superseded count failed'));
            });
            expect(screen.getByTestId('resource-total-count')).toHaveTextContent('43');
            expect(screen.getByTestId('resource-count-status')).toHaveTextContent('ready');
        });

        it('allows retrying a count that fails after a partial reload', async () => {
            const { rerender } = render(<ResourcesPage {...props} />);
            await act(async () => requests[0].resolve(42));
            rerender(<ResourcesPage {...props} resources={[...props.resources]} />);

            expect(requests).toHaveLength(2);
            await act(async () => requests[1].reject(new Error('Count temporarily unavailable')));
            expect(screen.getByTestId('resource-count-status')).toHaveTextContent('failed');
            fireEvent.click(screen.getByRole('button', { name: 'Retry count' }));

            expect(requests).toHaveLength(3);
            await act(async () => requests[2].resolve(43));
            expect(screen.getByTestId('resource-total-count')).toHaveTextContent('43');
            expect(screen.getByTestId('resource-count-status')).toHaveTextContent('ready');
        });

        it('keeps a ready server count without requesting another count after a partial reload', () => {
            const pagination = { ...props.pagination, total: 42, count_status: 'ready' as const };
            const { rerender } = render(<ResourcesPage {...props} pagination={pagination} />);
            rerender(<ResourcesPage {...props} pagination={pagination} resources={[...props.resources]} />);

            expect(requests).toHaveLength(0);
            expect(screen.getByTestId('resource-total-count')).toHaveTextContent('42');
            expect(screen.getByTestId('resource-count-status')).toHaveTextContent('ready');
        });
    });

    it('serializes the without SPDX license filter for navigation, counts, and cursor requests', async () => {
        axiosGetMock.mockImplementation((url: string) => {
            if (url === '/resources/filter-options') {
                return Promise.resolve({ data: { datacenters: [] } });
            }

            if (url === '/resources/count') {
                return Promise.resolve({ data: { filter_fingerprint: 'spdx-filter', total: 2, count_status: 'ready' } });
            }

            if (url === '/resources/load-more') {
                return Promise.resolve({ data: { resources: [], pagination: { has_more: false, next_cursor: null } } });
            }

            return Promise.resolve({ data: {} });
        });

        const props = {
            resources: [{ id: 1, title: 'First resource', publicstatus: 'draft' } as never],
            pagination: {
                per_page: 25,
                total: null,
                from: 1,
                to: 1,
                has_more: true,
                next_cursor: 'spdx-cursor',
                count_status: 'pending' as const,
                filter_fingerprint: 'spdx-filter',
            },
            sort: { key: 'id' as const, direction: 'asc' as const },
            filters: { without_spdx_license: true },
        };

        render(<ResourcesPage {...props} />);

        await waitFor(() => {
            const countCall = axiosGetMock.mock.calls.find(([url]) => url === '/resources/count');
            expect(countCall?.[1].params.get('without_spdx_license')).toBe('1');
        });

        await waitFor(() => expect(intersectionCallback).not.toBeNull());
        act(() => {
            intersectionCallback?.([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver);
        });
        await waitFor(() => {
            const loadMoreCall = axiosGetMock.mock.calls.find(([url]) => url === '/resources/load-more');
            expect(loadMoreCall?.[1].params.get('without_spdx_license')).toBe('1');
            expect(loadMoreCall?.[1].params.get('cursor')).toBe('spdx-cursor');
        });
        expect(axiosGetMock.mock.calls.filter(([url]) => url === '/resources/count')).toHaveLength(1);

        fireEvent.click(screen.getByTestId('select-without-spdx-license'));
        expect(routerMock.visit).toHaveBeenCalledWith(
            '/resources?sort_key=id&sort_direction=asc&per_page=25&without_spdx_license=1',
            expect.objectContaining({
                preserveState: false,
                replace: true,
            }),
        );

        routerMock.visit.mockClear();
        fireEvent.click(screen.getByRole('button', { name: /sort by the resource title/i }));
        expect(routerMock.visit).toHaveBeenCalledWith(
            '/resources?sort_key=title&sort_direction=asc&per_page=25&without_spdx_license=1',
            expect.objectContaining({
                preserveState: false,
                replace: true,
            }),
        );
    });

    it('ignores a stale asynchronous count response', async () => {
        axiosGetMock
            .mockResolvedValueOnce({ data: { datacenters: [] } })
            .mockResolvedValueOnce({ data: { filter_fingerprint: 'stale', total: 999, count_status: 'ready' } });

        render(
            <ResourcesPage
                resources={[]}
                pagination={{
                    per_page: 50,
                    total: null,
                    from: null,
                    to: null,
                    has_more: false,
                    next_cursor: null,
                    previous_cursor: null,
                    count_status: 'pending',
                    filter_fingerprint: 'current',
                }}
                sort={{ key: 'updated_at', direction: 'desc' }}
                filters={{}}
            />,
        );

        await waitFor(() => expect(axiosGetMock).toHaveBeenCalledTimes(2));
        expect(screen.getByTestId('resource-total-count')).toHaveTextContent('pending');
        expect(screen.getByTestId('resource-count-status')).toHaveTextContent('pending');
    });

    it('clears the loading state when a filter change aborts an in-flight cursor request', async () => {
        axiosGetMock.mockImplementation((url: string, config?: { signal?: AbortSignal }) => {
            if (url !== '/resources/load-more') {
                return Promise.resolve({ data: { datacenters: [] } });
            }

            return new Promise((_resolve, reject) => {
                config?.signal?.addEventListener('abort', () => reject({ isAxiosError: true, code: 'ERR_CANCELED' }), { once: true });
            });
        });

        render(
            <ResourcesPage
                resources={[{ id: 1, title: 'First resource', publicstatus: 'draft' } as never]}
                pagination={{
                    per_page: 50,
                    total: 2,
                    from: 1,
                    to: 1,
                    has_more: true,
                    next_cursor: 'next-cursor',
                    count_status: 'ready',
                }}
                sort={{ key: 'updated_at', direction: 'desc' }}
                filters={{}}
            />,
        );

        await waitFor(() => expect(intersectionCallback).not.toBeNull());
        act(() => {
            intersectionCallback?.([{ isIntersecting: true } as IntersectionObserverEntry], {} as IntersectionObserver);
        });

        await waitFor(() => expect(screen.getByTestId('resource-loading-state')).toHaveTextContent('true'));
        fireEvent.click(screen.getByTestId('select-datacenter'));

        await waitFor(() => expect(screen.getByTestId('resource-loading-state')).toHaveTextContent('false'));
    });

    afterEach(() => {
        document.head.innerHTML = '';
        Object.defineProperty(window, 'open', {
            value: originalOpen,
            configurable: true,
            writable: true,
        });
        if (originalClipboardDescriptor) {
            Object.defineProperty(navigator, 'clipboard', originalClipboardDescriptor);
        } else {
            Reflect.deleteProperty(navigator, 'clipboard');
        }
    });

    describe('datacenter filter persistence', () => {
        const listProps = {
            resources: [],
            pagination: {
                current_page: 1,
                last_page: 1,
                per_page: 50,
                total: 0,
                from: 0,
                to: 0,
                has_more: false,
            },
            sort: { key: 'updated_at' as const, direction: 'desc' as const },
            filters: {},
        };

        it('restores a valid stored datacenter only on a clean resources URL', async () => {
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 7 }));
            axiosGetMock.mockResolvedValueOnce({
                data: { datacenters: [{ id: 7, name: 'GFZ' }], resource_types: [], curators: [], statuses: [], year_range: {} },
            });

            render(<ResourcesPage {...listProps} />);

            await waitFor(() => {
                expect(routerMock.visit).toHaveBeenCalledWith(
                    '/resources?sort_key=updated_at&sort_direction=desc&per_page=50&datacenter_id=7',
                    expect.objectContaining({
                        preserveState: false,
                        replace: true,
                    }),
                );
            });
            expect(screen.getByTestId('resource-filter-state')).toHaveTextContent('"datacenter_id":7');
        });

        it('restores the stored without-datacenter selection', async () => {
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'without_datacenter' }));
            axiosGetMock.mockResolvedValueOnce({ data: { datacenters: [] } });

            render(<ResourcesPage {...listProps} />);

            await waitFor(() => {
                expect(routerMock.visit).toHaveBeenCalledWith(
                    '/resources?sort_key=updated_at&sort_direction=desc&per_page=50&without_datacenter=1',
                    expect.objectContaining({
                        preserveState: false,
                        replace: true,
                    }),
                );
            });
        });

        it('discards a stored datacenter that no longer exists', async () => {
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 99 }));
            axiosGetMock.mockResolvedValueOnce({ data: { datacenters: [{ id: 7, name: 'GFZ' }] } });

            render(<ResourcesPage {...listProps} />);

            await waitFor(() => expect(axiosGetMock).toHaveBeenCalledWith('/resources/filter-options'));
            await waitFor(() => expect(localStorage.getItem('ernie.resources.datacenter-filter.v1')).toBeNull());
            expect(routerMock.visit).not.toHaveBeenCalled();
        });

        it('does not restore over an explicit filtered URL', async () => {
            window.history.replaceState({}, '', '/resources?search=volcano');
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 7 }));
            axiosGetMock.mockResolvedValueOnce({ data: { datacenters: [{ id: 7, name: 'GFZ' }] } });

            render(<ResourcesPage {...listProps} filters={{ search: 'volcano' }} />);

            await waitFor(() => expect(axiosGetMock).toHaveBeenCalledWith('/resources/filter-options'));
            expect(routerMock.visit).not.toHaveBeenCalled();
            expect(screen.getByTestId('resource-filter-state')).toHaveTextContent('"search":"volcano"');
        });

        it('updates and clears only the datacenter preference after user changes', () => {
            render(<ResourcesPage {...listProps} filters={{ search: 'volcano' }} />);

            fireEvent.click(screen.getByTestId('select-datacenter'));
            expect(localStorage.getItem('ernie.resources.datacenter-filter.v1')).toBe(
                JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 7 }),
            );

            fireEvent.click(screen.getByTestId('clear-datacenter'));
            expect(localStorage.getItem('ernie.resources.datacenter-filter.v1')).toBeNull();
        });
    });

    it('shows and opens the URL migration only when the admin capability prop is true', async () => {
        const listProps = {
            resources: [],
            pagination: {
                current_page: 1,
                last_page: 1,
                per_page: 50,
                total: 0,
                from: 0,
                to: 0,
                has_more: false,
            },
            sort: { key: 'id' as const, direction: 'asc' as const },
        };
        const { rerender } = render(<ResourcesPage {...listProps} canUpdateDataCiteLandingPageUrls />);

        await userEvent.click(screen.getByTestId('resources-datacite-url-update'));
        expect(screen.getByTestId('datacite-url-update-modal-mock')).toHaveTextContent('resources');

        rerender(<ResourcesPage {...listProps} canUpdateDataCiteLandingPageUrls={false} />);
        expect(screen.queryByTestId('resources-datacite-url-update')).not.toBeInTheDocument();
    });

    it('renders a table with the streamlined dataset overview', async () => {
        const props = {
            resources: [
                {
                    id: 1,
                    doi: '10.9999/example',
                    year: 2024,
                    version: '2.0',
                    created_at: '2024-04-01T09:00:00Z',
                    updated_at: '2024-04-02T10:00:00Z',
                    resourcetypegeneral: 'Dataset',
                    title: 'Primary title',
                    first_author: { givenName: 'John', familyName: 'Doe' },
                    curator: 'Test Curator',
                    publicstatus: 'published',
                    landingPage: { id: 1, is_published: true, public_url: 'https://example.test/resource' },
                },
            ],
            pagination: {
                current_page: 1,
                last_page: 3,
                per_page: 50,
                total: 60,
                from: 1,
                to: 50,
                has_more: true,
            },
            sort: {
                key: 'id' as const,
                direction: 'asc' as const,
            },
        };

        render(<ResourcesPage {...props} />);

        expect(screen.getByTestId('app-layout')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1, name: /resources/i })).toBeInTheDocument();

        const table = screen.getByRole('table');
        expect(table).toBeInTheDocument();
        expect(within(table).getByRole('group', { name: /sort options for id and resource type/i })).toBeInTheDocument();
        expect(within(table).getByRole('group', { name: /sort options for doi and title/i })).toBeInTheDocument();

        const dataRows = within(table).getAllByRole('row').slice(1);
        const cells = within(dataRows[0]).getAllByRole('cell');
        const idResourceTypeCell = cells[1];
        const doiTitleCell = cells[2];

        expect(Array.from(idResourceTypeCell.querySelectorAll('span')).map((span) => span.textContent)).toEqual(['#1', 'Dataset']);
        expect(Array.from(doiTitleCell.querySelectorAll('span')).map((span) => span.textContent)).toEqual(['10.9999/example', 'Primary title']);

        expect(screen.queryByRole('columnheader', { name: /actions/i })).not.toBeInTheDocument();
        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await openResourceActionsMenu();
        expect(screen.getByTestId('resources-action-edit')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-delete')).toBeInTheDocument();
    });

    it('shows a friendly empty state when there are no resources', () => {
        render(
            <ResourcesPage
                resources={[]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 0,
                    from: 0,
                    to: 0,
                    has_more: false,
                }}
                sort={{ key: 'id', direction: 'asc' }}
            />,
        );

        expect(screen.getByText(/no resources found/i)).toBeInTheDocument();
    });

    it('uses a friendly placeholder when a resource has no DOI', () => {
        const props = {
            resources: [
                {
                    id: 99,
                    doi: null,
                    year: 2023,
                    title: 'Placeholder title',
                    resourcetypegeneral: 'Dataset',
                    curator: undefined,
                    publicstatus: 'curation',
                    landingPage: null,
                },
            ],
            pagination: {
                current_page: 1,
                last_page: 1,
                per_page: 50,
                total: 1,
                from: 1,
                to: 1,
                has_more: false,
            },
            sort: { key: 'id' as const, direction: 'asc' as const },
        };

        render(<ResourcesPage {...props} />);

        const dataRows = screen.getAllByRole('row').slice(1);
        expect(within(dataRows[0]).getByText('Not registered')).toBeInTheDocument();
    });

    it('opens the curation editor for the selected resource when the edit action is triggered', async () => {
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'published',
            landingPage: { id: 1, is_published: true, public_url: 'https://example.test/resource' },
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await clickResourceAction('resources-action-edit');

        expect(editorRouteMock).toHaveBeenCalledWith({
            query: { resourceId: resource.id },
        });
        expect(openDetachedTabMock).toHaveBeenCalledWith('/editor?resourceId=1');
        expect(screen.queryByTestId('blocked-editor-tabs-dialog')).not.toBeInTheDocument();
        expect(buildCurationQueryFromResourceMock).not.toHaveBeenCalled();
        expect(routerMock.get).not.toHaveBeenCalled();

        await clickResourceAction('resources-action-setup-landing-page');
        expect(await screen.findByTestId('setup-lp-context')).toHaveTextContent('resource-list');
    });

    it('opens the curation editor in a new tab when a resource row is clicked', () => {
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        fireEvent.click(screen.getByRole('row', { name: /open resource 10\.9999\/example in editor/i }));

        expect(editorRouteMock).toHaveBeenCalledWith({ query: { resourceId: resource.id } });
        expect(openDetachedTabMock).toHaveBeenCalledWith('/editor?resourceId=1');
        expect(routerMock.get).not.toHaveBeenCalled();
    });

    it('copies the trimmed DOI without opening the editor or DOI resolver', async () => {
        const resource = {
            id: 1,
            doi: ' 10.9999/example ',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        expect(screen.getByText('10.9999/example')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Copy DOI 10.9999/example to clipboard' }));

        await waitFor(() => expect(clipboardWriteTextMock).toHaveBeenCalledWith('10.9999/example'));
        expect(clipboardWriteTextMock).not.toHaveBeenCalledWith('https://doi.org/10.9999/example');
        expect(toastSuccessMock).toHaveBeenCalledWith('DOI copied to clipboard', {
            description: '10.9999/example',
            duration: 3000,
        });
        expect(screen.getByRole('button', { name: 'DOI 10.9999/example copied to clipboard' })).toBeInTheDocument();
        expect(editorRouteMock).not.toHaveBeenCalled();
        expect(openDetachedTabMock).not.toHaveBeenCalled();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('copies a DOI with native keyboard button activation without activating the row', async () => {
        const resource = {
            id: 2,
            doi: '10.9999/keyboard-copy',
            year: 2024,
            title: 'Keyboard copy resource',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        const copyButton = screen.getByRole('button', { name: 'Copy DOI 10.9999/keyboard-copy to clipboard' });
        copyButton.focus();
        await userEvent.keyboard('{Enter}');

        await waitFor(() => expect(clipboardWriteTextMock).toHaveBeenCalledWith('10.9999/keyboard-copy'));
        expect(editorRouteMock).not.toHaveBeenCalled();
        expect(openDetachedTabMock).not.toHaveBeenCalled();
    });

    it('resets the DOI copy success state after the feedback timeout', async () => {
        const resource = {
            id: 3,
            doi: '10.9999/temporary-feedback',
            year: 2024,
            title: 'Temporary feedback resource',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        vi.useFakeTimers();

        try {
            await act(async () => {
                fireEvent.click(screen.getByRole('button', { name: 'Copy DOI 10.9999/temporary-feedback to clipboard' }));
                await Promise.resolve();
            });

            expect(screen.getByRole('button', { name: 'DOI 10.9999/temporary-feedback copied to clipboard' })).toBeInTheDocument();

            act(() => vi.advanceTimersByTime(2000));

            expect(screen.getByRole('button', { name: 'Copy DOI 10.9999/temporary-feedback to clipboard' })).toBeInTheDocument();
        } finally {
            vi.useRealTimers();
        }
    });

    it('shows no DOI copy action for null, empty, or whitespace-only DOI values', () => {
        const resources = [
            { id: 4, doi: null, title: 'Null DOI resource' },
            { id: 5, doi: '', title: 'Empty DOI resource' },
            { id: 6, doi: '   ', title: 'Whitespace DOI resource' },
        ].map((resource) => ({
            ...resource,
            year: 2024,
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        }));

        render(
            <ResourcesPage
                resources={resources as never}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 3,
                    from: 1,
                    to: 3,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        expect(screen.getAllByText('Not registered')).toHaveLength(3);
        expect(screen.queryByRole('button', { name: /copy doi/i })).not.toBeInTheDocument();
    });

    it('shows a malformed saved DOI without offering a copy action', () => {
        const resource = {
            id: 9,
            doi: 'not-a-doi',
            year: 2024,
            title: 'Malformed DOI resource',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'draft',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        expect(screen.getByText('not-a-doi')).toBeInTheDocument();
        expect(screen.queryByTestId('copy-resource-doi-9')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /copy doi not-a-doi/i })).not.toBeInTheDocument();
    });

    it('reports a rejected DOI clipboard write without activating the row', async () => {
        clipboardWriteTextMock.mockRejectedValueOnce(new Error('Clipboard write denied'));

        const resource = {
            id: 7,
            doi: '10.9999/rejected-copy',
            year: 2024,
            title: 'Rejected copy resource',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Copy DOI 10.9999/rejected-copy to clipboard' }));

        await waitFor(() => expect(toastErrorMock).toHaveBeenCalledWith('Failed to copy DOI to clipboard'));
        expect(screen.getByRole('button', { name: 'Copy DOI 10.9999/rejected-copy to clipboard' })).toBeInTheDocument();
        expect(editorRouteMock).not.toHaveBeenCalled();
        expect(openDetachedTabMock).not.toHaveBeenCalled();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('reports an unavailable Clipboard API without throwing or activating the row', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            value: undefined,
            configurable: true,
            writable: true,
        });

        const resource = {
            id: 8,
            doi: '10.9999/unavailable-copy',
            year: 2024,
            title: 'Unavailable copy resource',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Copy DOI 10.9999/unavailable-copy to clipboard' }));

        await waitFor(() => expect(toastErrorMock).toHaveBeenCalledWith('Failed to copy DOI to clipboard'));
        expect(editorRouteMock).not.toHaveBeenCalled();
        expect(openDetachedTabMock).not.toHaveBeenCalled();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('opens the curation editor from keyboard row activation', () => {
        const resource = {
            id: 7,
            doi: null,
            year: 2024,
            title: 'Keyboard resource',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        const row = screen.getByRole('row', { name: /open resource keyboard resource in editor/i });
        fireEvent.keyDown(row, { key: 'Enter' });

        expect(openDetachedTabMock).toHaveBeenCalledWith('/editor?resourceId=7');

        openDetachedTabMock.mockClear();
        editorRouteMock.mockClear();

        fireEvent.keyDown(row, { key: ' ' });

        expect(editorRouteMock).toHaveBeenCalledWith({ query: { resourceId: resource.id } });
        expect(openDetachedTabMock).toHaveBeenCalledWith('/editor?resourceId=7');
    });

    it('opens the editor when a non-interactive status cell area is clicked', () => {
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        fireEvent.click(screen.getByText('Curation'));

        expect(editorRouteMock).toHaveBeenCalledWith({ query: { resourceId: resource.id } });
        expect(openDetachedTabMock).toHaveBeenCalledWith('/editor?resourceId=1');
    });

    it('does not open the editor when the row checkbox is clicked', () => {
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'curation',
            landingPage: null,
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));

        expect(screen.getByText(/^1 resource selected$/i)).toBeInTheDocument();
        expect(editorRouteMock).not.toHaveBeenCalled();
        expect(openDetachedTabMock).not.toHaveBeenCalled();
    });

    it('keeps the published status badge behavior separate from row editor activation', () => {
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'published',
            landingPage: { id: 1, is_published: true, public_url: 'https://example.test/resource' },
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /published - click to open doi and copy url to clipboard/i }));

        expect(clipboardWriteTextMock).toHaveBeenCalledWith('https://doi.org/10.9999/example');
        expect(openMock).toHaveBeenCalledWith('https://doi.org/10.9999/example', '_blank', 'noopener,noreferrer');
        expect(editorRouteMock).not.toHaveBeenCalled();
        expect(openDetachedTabMock).not.toHaveBeenCalled();
    });

    it('opens and copies the tokenized preview URL from a review badge', () => {
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'review',
            landingPage: {
                id: 1,
                is_published: false,
                public_url: 'https://example.test/resource',
                preview_url: 'https://example.test/resource?preview=secret-token',
            },
        };

        render(
            <ResourcesPage
                resources={[resource]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /review - click to open preview page and copy url to clipboard/i }));

        expect(clipboardWriteTextMock).toHaveBeenCalledWith('https://example.test/resource?preview=secret-token');
        expect(openMock).toHaveBeenCalledWith('https://example.test/resource?preview=secret-token', '_blank', 'noopener,noreferrer');
        expect(clipboardWriteTextMock).not.toHaveBeenCalledWith('https://example.test/resource');
    });

    it('does not expose review badge interactions without review-link permission', () => {
        authUserMock.can_send_review_links = false;
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'review',
            landingPage: {
                id: 1,
                is_published: false,
                public_url: 'https://example.test/resource',
                preview_url: 'https://example.test/resource?preview=secret-token',
            },
        };

        render(
            <ResourcesPage
                resources={[resource]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        expect(screen.queryByRole('button', { name: /review - click to open preview page/i })).not.toBeInTheDocument();

        const reviewLabel = screen.getByText('Review');
        expect(reviewLabel.parentElement).not.toHaveAttribute('role', 'button');
        expect(reviewLabel.parentElement).not.toHaveAttribute('data-resource-row-action');
        expect(clipboardWriteTextMock).not.toHaveBeenCalled();
        expect(openMock).not.toHaveBeenCalled();
    });

    it('does not activate the row when an interactive status badge text node is clicked', () => {
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            title: 'Primary title',
            resourcetypegeneral: 'Dataset',
            curator: 'Test Curator',
            publicstatus: 'published',
            landingPage: { id: 1, is_published: true, public_url: 'https://example.test/resource' },
        };

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 50,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
                sort={{ key: 'id' as const, direction: 'asc' as const }}
            />,
        );

        const statusTextNode = screen.getByText('Published').firstChild;

        expect(statusTextNode).toBeInstanceOf(Text);

        fireEvent.click(statusTextNode as Text);

        expect(openMock).toHaveBeenCalledWith('https://doi.org/10.9999/example', '_blank', 'noopener,noreferrer');
        expect(editorRouteMock).not.toHaveBeenCalled();
        expect(openDetachedTabMock).not.toHaveBeenCalled();
    });
});

import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { fireEvent, render, screen, waitFor, within } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ResourcesPage, { mergeLoadMorePagination } from '@/pages/resources';

const routerMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn() }));
const axiosGetMock = vi.hoisted(() => vi.fn());
const buildCurationQueryFromResourceMock = vi.hoisted(() => vi.fn());
const editorRouteMock = vi.hoisted(() =>
    vi.fn(({ query }: { query?: Record<string, string | number> } = {}) => ({
        url: query ? `/editor?${new URLSearchParams(Object.entries(query).map(([key, value]) => [key, String(value)])).toString()}` : '/editor',
        method: 'get',
    })),
);
const openDetachedTabMock = vi.hoisted(() => vi.fn());
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

vi.mock('@/routes', () => ({
    editor: editorRouteMock,
}));
vi.mock('@/lib/detached-tab', () => ({ openDetachedTab: openDetachedTabMock }));

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
    }: {
        filters: { datacenter_id?: number; without_datacenter?: boolean; search?: string };
        onFilterChange: (filters: Record<string, unknown>) => void;
    }) => (
        <div data-testid="resources-filters">
            <span data-testid="resource-filter-state">{JSON.stringify(filters)}</span>
            <button type="button" data-testid="select-datacenter" onClick={() => onFilterChange({ ...filters, datacenter_id: 7 })}>
                Select datacenter
            </button>
            <button type="button" data-testid="select-without-datacenter" onClick={() => onFilterChange({ without_datacenter: true })}>
                Select without datacenter
            </button>
            <button type="button" data-testid="clear-datacenter" onClick={() => onFilterChange({ search: filters.search })}>
                Clear datacenter
            </button>
        </div>
    ),
}));
vi.mock('@/components/landing-pages/modals/SetupLandingPageModal', () => ({ default: () => null }));
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

    beforeEach(() => {
        authUserMock.can_send_review_links = true;
        routerMock.get.mockClear();
        routerMock.delete.mockClear();
        routerMock.reload.mockClear();
        routerMock.visit.mockClear();
        axiosGetMock.mockReset();
        axiosGetMock.mockResolvedValue({ data: {} });
        localStorage.clear();
        window.history.replaceState({}, '', '/resources');
        buildCurationQueryFromResourceMock.mockReset();
        buildCurationQueryFromResourceMock.mockResolvedValue({});
        editorRouteMock.mockClear();
        openDetachedTabMock.mockReset();
        openDetachedTabMock.mockReturnValue({} as Window);
        originalOpen = window.open;
        originalClipboardDescriptor = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
        openMock = vi.fn().mockReturnValue({ closed: false });
        clipboardWriteTextMock = vi.fn().mockResolvedValue(undefined);
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

        global.IntersectionObserver = vi.fn().mockImplementation(function () {
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

    it('retains exact initial totals when merging a count-free load-more page', () => {
        expect(
            mergeLoadMorePagination(
                {
                    current_page: 1,
                    last_page: 4,
                    per_page: 20,
                    total: 73,
                    from: 1,
                    to: 20,
                    has_more: true,
                },
                {
                    current_page: 2,
                    per_page: 20,
                    from: 21,
                    to: 40,
                    has_more: true,
                },
            ),
        ).toEqual({
            current_page: 2,
            last_page: 4,
            per_page: 20,
            total: 73,
            from: 21,
            to: 40,
            has_more: true,
        });
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
                expect(routerMock.visit).toHaveBeenCalledWith('/resources?sort_key=updated_at&sort_direction=desc&datacenter_id=7', {
                    preserveState: false,
                    replace: true,
                });
            });
            expect(screen.getByTestId('resource-filter-state')).toHaveTextContent('"datacenter_id":7');
        });

        it('restores the stored without-datacenter selection', async () => {
            localStorage.setItem('ernie.resources.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'without_datacenter' }));
            axiosGetMock.mockResolvedValueOnce({ data: { datacenters: [] } });

            render(<ResourcesPage {...listProps} />);

            await waitFor(() => {
                expect(routerMock.visit).toHaveBeenCalledWith('/resources?sort_key=updated_at&sort_direction=desc&without_datacenter=1', {
                    preserveState: false,
                    replace: true,
                });
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

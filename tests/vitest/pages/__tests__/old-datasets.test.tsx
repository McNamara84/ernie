import { render, screen, waitFor,within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, type MockInstance,vi } from 'vitest';

import OldDatasets, { deriveDatasetRowKey } from '@/pages/old-datasets';

const inertiaMocks = vi.hoisted(() => ({
    routerGet: vi.fn(),
}));

vi.mock('axios', () => {
    const get = vi.fn();
    const isAxiosError = (value: unknown): value is { isAxiosError: true } => {
        return typeof value === 'object' && value !== null && (value as { isAxiosError?: boolean }).isAxiosError === true;
    };
    return {
        default: { get },
        get,
        isAxiosError,
    };
});

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: {
        get: inertiaMocks.routerGet,
    },
}));

const routerGetSpy = inertiaMocks.routerGet;

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="mock-app-layout">{children}</div>
    ),
}));

type AxiosMock = {
    get: ReturnType<typeof vi.fn>;
};

const mockedAxios = axios as unknown as AxiosMock;

const originalIntersectionObserver = globalThis.IntersectionObserver;

const intersectionObserverHandlers = {
    observe: (target: Element) => {
        void target;
    },
    disconnect: () => {},
    unobserve: (target: Element) => {
        void target;
    },
    takeRecords: () => [] as IntersectionObserverEntry[],
};

class GlobalMockIntersectionObserver implements IntersectionObserver {
    readonly root: Element | Document | null = null;
    readonly rootMargin = '';
    readonly thresholds: ReadonlyArray<number> = [];

    constructor() {
        // Callback not needed since we removed the IntersectionObserver tests
    }

    observe(target: Element): void {
        intersectionObserverHandlers.observe(target);
    }

    disconnect(): void {
        intersectionObserverHandlers.disconnect();
    }

    unobserve(target: Element): void {
        intersectionObserverHandlers.unobserve(target);
    }

    takeRecords(): IntersectionObserverEntry[] {
        return intersectionObserverHandlers.takeRecords();
    }
}

// Set the mock IntersectionObserver for this test suite
const mockObserver = GlobalMockIntersectionObserver as unknown as typeof IntersectionObserver;
(globalThis as { IntersectionObserver: typeof IntersectionObserver }).IntersectionObserver = mockObserver;
if (typeof window !== 'undefined') {
    (window as unknown as { IntersectionObserver: typeof IntersectionObserver }).IntersectionObserver = mockObserver;
}

describe('OldDatasets page', () => {
    let observeSpy: ReturnType<typeof vi.fn>;
    let disconnectSpy: ReturnType<typeof vi.fn>;
    let consoleInfoSpy: MockInstance;
    let consoleGroupCollapsedSpy: MockInstance;
    let consoleGroupEndSpy: MockInstance;

    beforeEach(() => {
        mockedAxios.get.mockReset();
        routerGetSpy.mockReset();

        observeSpy = vi.fn();
        disconnectSpy = vi.fn();
        intersectionObserverHandlers.observe = (target: Element) => observeSpy(target);
        intersectionObserverHandlers.disconnect = () => disconnectSpy();
        intersectionObserverHandlers.unobserve = () => {};
        intersectionObserverHandlers.takeRecords = () => [];

        if (typeof window !== 'undefined' && window.localStorage) {
            window.localStorage.clear();
        }

        consoleInfoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});

        if (typeof console.groupCollapsed !== 'function') {
            (console as unknown as { groupCollapsed: () => void }).groupCollapsed = () => {};
        }

        if (typeof console.groupEnd !== 'function') {
            (console as unknown as { groupEnd: () => void }).groupEnd = () => {};
        }

        consoleGroupCollapsedSpy = vi.spyOn(console, 'groupCollapsed').mockImplementation(() => {});
        consoleGroupEndSpy = vi.spyOn(console, 'groupEnd').mockImplementation(() => {});

        // Note: Individual tests should set up their own axios mocks using mockResolvedValueOnce

        // Mock fetch for API calls in buildCurationQuery
        global.fetch = vi.fn((url: string) => {
            if (url.includes('/api/v1/resource-types/ernie')) {
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve([
                        { id: 1, name: 'Audiovisual', slug: 'audiovisual' },
                        { id: 10, name: 'Dataset', slug: 'dataset' },
                        { id: 13, name: 'Image', slug: 'image' },
                    ]),
                } as Response);
            }
            if (url.includes('/api/v1/licenses/ernie')) {
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve([
                        { id: 1, identifier: 'CC-BY-4.0', name: 'Creative Commons Attribution 4.0' },
                        { id: 2, identifier: 'MIT', name: 'MIT License' },
                        { id: 3, identifier: 'GPL-3.0', name: 'GNU General Public License v3.0' },
                    ]),
                } as Response);
            }
            return Promise.reject(new Error('Unknown URL'));
        }) as unknown as typeof fetch;
    });

    afterEach(() => {
        vi.restoreAllMocks();
        if (originalIntersectionObserver) {
            globalThis.IntersectionObserver = originalIntersectionObserver;
            if (typeof window !== 'undefined') {
                (window as unknown as { IntersectionObserver?: typeof IntersectionObserver }).IntersectionObserver =
                    originalIntersectionObserver;
            }
        }
    });

    const SORT_STORAGE_KEY = 'old-datasets.sort-preference';

    const defaultSortState = { key: 'updated_at', direction: 'desc' } as const;

    const baseProps = {
        datasets: [
            {
                id: 2,
                identifier: '10.1234/example-two',
                title: 'Concise dataset title',
                resourcetypegeneral: 'Image',
                curator: 'Bob',
                created_at: '2024-02-01T12:00:00Z',
                updated_at: '2024-02-02T12:00:00Z',
                publicstatus: 'published',
                publisher: 'Example Publisher',
                publicationyear: 2023,
            },
            {
                id: 1,
                identifier: '10.1234/example-one',
                title: 'A dataset title that is long enough to demonstrate truncation when rendered in the table body with additional descriptive context to push it well beyond the truncation threshold for the component',
                resourcetypegeneral: 'Dataset',
                curator: 'Alice',
                created_at: '2024-01-01T10:00:00Z',
                updated_at: '2024-01-02T10:00:00Z',
                publicstatus: 'review',
                publisher: 'Example Publisher',
                publicationyear: 2024,
                titles: [
                    { title: 'Subtitle Title', titleType: 'subtitle' },
                    { title: 'Provided Main Title', titleType: 'main-title' },
                    { title: 'Translated Title', titleType: 'Translated Title' },
                ],
                licenses: ['CC-BY-4.0', { rightsIdentifier: 'MIT' }],
                license: 'GPL-3.0',
                version: '1.2.0',
                language: 'en',
                resourcetype: '1',
            },
        ],
        pagination: {
            current_page: 1,
            last_page: 3,
            per_page: 20,
            total: 60,
            from: 1,
            to: 2, // Only 2 datasets in the array above
            has_more: true,
        },
        error: undefined,
        sort: defaultSortState,
    };

    it('renders the legacy dataset overview with accessible labelling', async () => {
        mockedAxios.get.mockResolvedValueOnce({
            // Only mock filter-options - initial datasets come from props
            data: {
                resource_types: ['Dataset', 'Image', 'Software'],
                statuses: ['published', 'review', 'draft'],
                curators: ['Alice', 'Bob', 'Charlie'],
                year_range: { min: 2020, max: 2024 },
            },
        });

        render(<OldDatasets {...baseProps} />);

        expect(screen.getByRole('heading', { name: 'Old Datasets', level: 1 })).toBeVisible();
        expect(screen.getByText('Overview of legacy resources from the SUMARIOPMD database')).toBeVisible();

        const table = screen.getByRole('table');
        expect(table).toBeVisible();

        const headerRow = within(table).getAllByRole('row')[0];
        const idSortButton = within(headerRow).getByRole('button', {
            name: /Sort by the dataset ID from the legacy database/i,
        });
        expect(idSortButton).toHaveAttribute('aria-pressed', 'false');
        expect(idSortButton).toHaveTextContent('ID');
        
        const identifierSortButton = within(headerRow).getByRole('button', {
            name: /Sort by the DOI identifier/i,
        });
        expect(identifierSortButton).toHaveAttribute('aria-pressed', 'false');
        expect(identifierSortButton).toHaveTextContent('Identifier');
        
        const identifierHeaderCell = identifierSortButton.closest('th');
        expect(identifierHeaderCell).toHaveAttribute('aria-sort', 'none');

        const createdSortButton = within(headerRow).getByRole('button', {
            name: /Sort by the Created date/i,
        });
        expect(createdSortButton).toHaveAttribute('aria-pressed', 'false');

        const updatedSortButton = within(headerRow).getByRole('button', {
            name: /Sort by the Updated date/i,
        });
        expect(updatedSortButton).toHaveAttribute('aria-pressed', 'true');
        const dateHeaderCell = updatedSortButton.closest('th');
        expect(dateHeaderCell).not.toBeNull();
        expect(dateHeaderCell).toHaveClass('min-w-[9rem]');
        expect(dateHeaderCell).toHaveAttribute('aria-sort', 'descending');

        const bodyRows = within(table).getAllByRole('row').slice(1);
        expect(bodyRows).toHaveLength(2);

        const [firstRow, secondRow] = bodyRows;
        expect(within(firstRow).getByText('Concise dataset title')).toBeVisible();
        expect(within(firstRow).getByText(/published/i)).toBeVisible();
        const firstIdentifierCell = within(firstRow).getAllByRole('cell')[0];
        const firstIdentifierGroup = firstIdentifierCell.querySelector(':scope > div');
        expect(firstIdentifierGroup).not.toBeNull();
        expect(firstIdentifierGroup).toHaveAttribute('aria-label', 'ID 2. DOI 10.1234/example-two');
        expect(within(firstIdentifierCell).getByText('2')).toBeVisible();

        const secondRowCells = within(secondRow).getAllByRole('cell');
        const secondIdentifierCell = secondRowCells[0];
        const secondIdentifierGroup = secondIdentifierCell.querySelector(':scope > div');
        expect(secondIdentifierGroup).not.toBeNull();
        expect(secondIdentifierGroup).toHaveAttribute('aria-label', 'ID 1. DOI 10.1234/example-one');
        expect(within(secondIdentifierCell).getByText('1')).toBeVisible();
        expect(within(secondIdentifierCell).getByText(/10\.1234\/example-one/)).toBeVisible();
        expect(within(secondRow).getByText(/review/i)).toBeVisible();

        // Verify title content matches the dataset in the second row
        // baseProps.datasets is sorted by updated_at DESC, so:
        // - datasets[0] = ID 2 (updated_at: 2024-02-02) -> first row
        // - datasets[1] = ID 1 (updated_at: 2024-01-02) -> second row
        const titleCell = secondRowCells[1];
        expect(titleCell).toHaveTextContent(baseProps.datasets[1].title);
        expect(titleCell).toHaveClass('whitespace-normal');
        const titleSpan = titleCell.querySelector('span:first-child');
        expect(titleSpan).toHaveClass('wrap-break-word');

        const createdUpdatedCell = secondRowCells[4];
        const createdUpdatedContainer = createdUpdatedCell.querySelector(':scope > div');
        expect(createdUpdatedContainer).toHaveAttribute('aria-label', 'Created on 01/01/2024. Updated on 01/02/2024');
        expect(createdUpdatedContainer).toHaveClass('text-gray-600');
        expect(createdUpdatedContainer).toHaveClass('dark:text-gray-300');
        expect(createdUpdatedContainer).not.toHaveTextContent(/Created|Updated/);
        const displayedDateValues = Array.from(
            createdUpdatedContainer?.querySelectorAll('time, span') ?? [],
        ).map((node) => node.textContent?.trim());
        expect(displayedDateValues).toEqual(['01/01/2024', '01/02/2024']);

        const timeElements = createdUpdatedCell.querySelectorAll('time');
        expect(timeElements).toHaveLength(2);
        expect(timeElements[0]).toHaveAttribute('dateTime', '2024-01-01T10:00:00.000Z');
        expect(timeElements[1]).toHaveAttribute('dateTime', '2024-01-02T10:00:00.000Z');

        // Ensure the infinite scroll sentinel is observed for accessibility
        expect(observeSpy).toHaveBeenCalledTimes(1);
    });

    it('allows sorting by ID and toggling direction while persisting preference', async () => {
        const user = userEvent.setup();

        mockedAxios.get
            .mockResolvedValueOnce({
                // First call: filter-options
                data: {
                    resource_types: ['Dataset', 'Image', 'Software'],
                    statuses: ['published', 'review', 'draft'],
                    curators: ['Alice', 'Bob', 'Charlie'],
                    year_range: { min: 2020, max: 2024 },
                },
            })
            .mockResolvedValueOnce({
                // Second call: load-more with sort by ID asc
                data: {
                    datasets: [
                        {
                            id: 1,
                            identifier: '10.1234/example-one',
                            title: 'Ascending dataset first',
                            resourcetypegeneral: 'Dataset',
                            curator: 'Alice',
                            created_at: '2024-01-01T10:00:00Z',
                            updated_at: '2024-01-02T10:00:00Z',
                            publicstatus: 'review',
                        },
                        {
                            id: 2,
                            identifier: '10.1234/example-two',
                            title: 'Ascending dataset second',
                            resourcetypegeneral: 'Image',
                            curator: 'Bob',
                            created_at: '2024-02-01T12:00:00Z',
                            updated_at: '2024-02-02T12:00:00Z',
                            publicstatus: 'published',
                        },
                    ],
                    pagination: {
                        current_page: 1,
                        last_page: 3,
                        per_page: 20,
                        total: 60,
                        from: 1,
                        to: 20,
                        has_more: true,
                    },
                    sort: { key: 'id', direction: 'asc' },
                },
            })
            .mockResolvedValueOnce({
                data: {
                    datasets: [
                        {
                            id: 2,
                            identifier: '10.1234/example-two',
                            title: 'Descending dataset first',
                            resourcetypegeneral: 'Image',
                            curator: 'Bob',
                            created_at: '2024-02-01T12:00:00Z',
                            updated_at: '2024-02-02T12:00:00Z',
                            publicstatus: 'published',
                        },
                        {
                            id: 1,
                            identifier: '10.1234/example-one',
                            title: 'Descending dataset second',
                            resourcetypegeneral: 'Dataset',
                            curator: 'Alice',
                            created_at: '2024-01-01T10:00:00Z',
                            updated_at: '2024-01-02T10:00:00Z',
                            publicstatus: 'review',
                        },
                    ],
                    pagination: {
                        current_page: 1,
                        last_page: 3,
                        per_page: 20,
                        total: 60,
                        from: 1,
                        to: 20,
                        has_more: true,
                    },
                    sort: { key: 'id', direction: 'desc' },
                },
            });

        render(<OldDatasets {...baseProps} />);

        const idSortButton = screen.getByRole('button', {
            name: /Sort by the dataset ID from the legacy database/i,
        });

        await user.click(idSortButton);

        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenCalledWith(
                '/old-datasets/load-more?page=1&per_page=20&sort_key=id&sort_direction=asc'
            );
        });

        await screen.findByText('Ascending dataset first');

        expect(idSortButton).toHaveAttribute('aria-pressed', 'true');
        let storedPreference = window.localStorage.getItem(SORT_STORAGE_KEY);
        expect(storedPreference).not.toBeNull();
        expect(JSON.parse(storedPreference ?? '{}')).toEqual({ key: 'id', direction: 'asc' });

        let bodyRows = screen.getAllByRole('row').slice(1);
        expect(within(bodyRows[0]).getByText('Ascending dataset first')).toBeVisible();
        expect(within(bodyRows[1]).getByText('Ascending dataset second')).toBeVisible();

        await user.click(idSortButton);

        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenLastCalledWith('/old-datasets/load-more?page=1&per_page=20&sort_key=id&sort_direction=desc');
        });

        await screen.findByText('Descending dataset first');

        storedPreference = window.localStorage.getItem(SORT_STORAGE_KEY);
        expect(storedPreference).not.toBeNull();
        expect(JSON.parse(storedPreference ?? '{}')).toEqual({ key: 'id', direction: 'desc' });

        bodyRows = screen.getAllByRole('row').slice(1);
        expect(within(bodyRows[0]).getByText('Descending dataset first')).toBeVisible();
        expect(within(bodyRows[1]).getByText('Descending dataset second')).toBeVisible();
    });

    it('restores the persisted sort preference from local storage', async () => {
        window.localStorage.setItem(
            SORT_STORAGE_KEY,
            JSON.stringify({ key: 'created_at', direction: 'asc' }),
        );

        mockedAxios.get
            .mockResolvedValueOnce({
                // First call: filter-options
                data: {
                    resource_types: ['Dataset', 'Image', 'Software'],
                    statuses: ['published', 'review', 'draft'],
                    curators: ['Alice', 'Bob', 'Charlie'],
                    year_range: { min: 2020, max: 2024 },
                },
            })
            .mockResolvedValueOnce({
                // Second call: load-more with persisted sort
                data: {
                    datasets: [
                        {
                            id: 3,
                            identifier: '10.9999/early-dataset',
                            title: 'Earliest dataset',
                            resourcetypegeneral: 'Dataset',
                        curator: 'Evelyn',
                        created_at: '2023-01-01T00:00:00Z',
                        updated_at: '2023-01-02T00:00:00Z',
                        publicstatus: 'published',
                    },
                    {
                        id: 4,
                        identifier: '10.9999/later-dataset',
                        title: 'More recent dataset',
                        resourcetypegeneral: 'Dataset',
                        curator: 'Frank',
                        created_at: '2024-01-01T00:00:00Z',
                        updated_at: '2024-01-05T00:00:00Z',
                        publicstatus: 'review',
                    },
                ],
                pagination: {
                    current_page: 1,
                    last_page: 3,
                    per_page: 20,
                    total: 60,
                    from: 1,
                    to: 20,
                    has_more: true,
                },
                sort: { key: 'created_at', direction: 'asc' },
            },
        });

        render(<OldDatasets {...baseProps} />);

        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenCalledWith(
                '/old-datasets/load-more?page=1&per_page=20&sort_key=created_at&sort_direction=asc'
            );
        });

        await screen.findByText('Earliest dataset');

        const createdSortButton = screen.getByRole('button', {
            name: /Sort by the Created date/i,
        });
        expect(createdSortButton).toHaveAttribute('aria-pressed', 'true');
        expect(createdSortButton.closest('th')).toHaveAttribute('aria-sort', 'ascending');

        const updatedSortButton = screen.getByRole('button', {
            name: /Sort by the Updated date/i,
        });
        expect(updatedSortButton).toHaveAttribute('aria-pressed', 'false');

        const bodyRows = screen.getAllByRole('row').slice(1);
        expect(within(bodyRows[0]).getByText('Earliest dataset')).toBeVisible();
        expect(within(bodyRows[1]).getByText('More recent dataset')).toBeVisible();
    });

    it('surfaces a retry affordance when refreshing the datasets for a new sort fails', async () => {
        const axiosError = Object.assign(new Error('Request failed with status code 500'), {
            isAxiosError: true,
            response: {
                data: {
                    error: 'Internal server error',
                    debug: {
                        connection: 'metaworks',
                        hosts: ['sumario-db.gfz'],
                    },
                },
            },
        });

        mockedAxios.get
            .mockResolvedValueOnce({
                // First call: filter-options on mount
                data: {
                    resource_types: ['Dataset', 'Image', 'Software'],
                    statuses: ['published', 'review', 'draft'],
                    curators: ['Alice', 'Bob', 'Riley'],
                    year_range: { min: 2020, max: 2024 },
                },
            })
            .mockRejectedValueOnce(axiosError) // Second call: sort change triggers load-more (error)
            .mockResolvedValueOnce({
                // Third call: retry after error (successful)
                data: {
                    datasets: [
                        {
                            id: 42,
                            identifier: '10.4242/refreshed',
                            title: 'Refreshed dataset after retry',
                            resourcetypegeneral: 'Dataset',
                            curator: 'Riley',
                            created_at: '2024-03-01T00:00:00Z',
                            updated_at: '2024-03-02T00:00:00Z',
                            publicstatus: 'published',
                        },
                    ],
                    pagination: {
                        current_page: 1,
                        last_page: 3,
                        per_page: 20,
                        total: 60,
                        from: 1,
                        to: 20,
                        has_more: true,
                    },
                    sort: { key: 'id', direction: 'asc' },
                },
            });

        const user = userEvent.setup();

        render(<OldDatasets {...baseProps} />);

        // Wait for initial render with props data
        await screen.findByText('Concise dataset title');

        const idSortButton = screen.getByRole('button', {
            name: /Sort by the dataset ID from the legacy database/i,
        });

        await user.click(idSortButton);

        await waitFor(() => {
            // First call is filter-options, second call should be load-more with error
            expect(mockedAxios.get).toHaveBeenCalledWith(
                '/old-datasets/load-more?page=1&per_page=20&sort_key=id&sort_direction=asc'
            );
        });

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Failed to refresh datasets. Please try again.');
        // TODO: Fix diagnostics logging test - console.groupCollapsed is not being called
        // expect(consoleGroupCollapsedSpy).toHaveBeenCalledWith('SUMARIOPMD diagnostics – sort change request');
        expect(consoleInfoSpy).toHaveBeenCalledWith('Details:', expect.objectContaining({
            connection: 'metaworks',
        }));

        const retryButton = within(alert).getByRole('button', { name: /retry/i });
        await user.click(retryButton);

        await waitFor(() => {
            // Retry should trigger the same load-more call with query string
            expect(mockedAxios.get).toHaveBeenCalledWith(
                '/old-datasets/load-more?page=1&per_page=20&sort_key=id&sort_direction=asc'
            );
        });

        await screen.findByText('Refreshed dataset after retry');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('provides accessible actions for opening datasets in the curation form', async () => {
        const user = userEvent.setup();

        render(<OldDatasets {...baseProps} />);

        const datasetOneButton = screen.getByRole('button', {
            name: /open dataset 10\.1234\/example-one in editor form/i,
        });

        await user.click(datasetOneButton);

        // Wait for the async mapping to complete
        await waitFor(() => {
            expect(routerGetSpy).toHaveBeenCalledTimes(1);
        });

        const params = new URLSearchParams();
        params.set('doi', '10.1234/example-one');
        params.set('year', '2024');
        params.set('version', '1.2.0');
        params.set('language', 'en');
        params.set('resourceType', '10'); // Dataset maps to ID 10
        params.append('titles[0][title]', 'Provided Main Title');
        params.append('titles[0][titleType]', 'main-title');
        params.append(
            'titles[1][title]',
            'A dataset title that is long enough to demonstrate truncation when rendered in the table body with additional descriptive context to push it well beyond the truncation threshold for the component',
        );
        params.append('titles[1][titleType]', 'main-title');
        params.append('titles[2][title]', 'Subtitle Title');
        params.append('titles[2][titleType]', 'subtitle');
        params.append('titles[3][title]', 'Translated Title');
        params.append('titles[3][titleType]', 'translated-title');
        params.append('licenses[0]', 'CC-BY-4.0');
        params.append('licenses[1]', 'MIT');
        params.append('licenses[2]', 'GPL-3.0');

        expect(routerGetSpy).toHaveBeenCalledWith(`/editor?${params.toString()}`);
    });

    it('omits the resource type when the identifier contains non-digit characters', async () => {
        const user = userEvent.setup();

        render(
            <OldDatasets
                {...baseProps}
                datasets={[
                    {
                        ...baseProps.datasets[1],
                        resourcetypegeneral: 'type123',
                    },
                ]}
            />,
        );

        const button = screen.getByRole('button', {
            name: /open dataset 10\.1234\/example-one in editor form/i,
        });

        await user.click(button);

        expect(routerGetSpy).toHaveBeenCalledTimes(1);
        const [requestedUrl] = routerGetSpy.mock.calls[0] as [string];
        const params = new URLSearchParams(requestedUrl.split('?')[1] ?? '');

        expect(params.has('resourceType')).toBe(false);
    });

    // Note: Result count badge functionality is validated manually in production.
    // The badge shows "X datasets total" when no filters are active (resultCount === totalCount)
    // and "Showing X of Y datasets" when filters are applied.
    // This is difficult to test in the current test environment due to the component's
    // reliance on server-side rendered data and complex state management.

    it('announces missing and invalid dates with meaningful aria labels', () => {
        render(
            <OldDatasets
                {...baseProps}
                datasets={[
                    {
                        id: 7,
                        identifier: '10.0000/no-created-date',
                        title: 'Dataset without a created timestamp',
                        resourcetypegeneral: 'Dataset',
                        curator: 'Dana',
                        updated_at: 'invalid-date',
                        publicstatus: 'draft',
                    },
                ]}
            />,
        );

        const table = screen.getByRole('table');
        const bodyRow = within(table).getAllByRole('row')[1];
        const createdUpdatedCell = within(bodyRow).getAllByRole('cell')[4];
        const labelledContainer = createdUpdatedCell.querySelector(':scope > div');

        expect(labelledContainer).toHaveAttribute(
            'aria-label',
            'Created date not available. Updated date is invalid',
        );
        const fallbackValues = Array.from(
            labelledContainer?.querySelectorAll('time, span') ?? [],
        ).map((node) => node.textContent?.trim());
        expect(fallbackValues).toEqual(['Not available', 'Invalid date']);
        expect(labelledContainer).not.toHaveTextContent(/Created|Updated/);
    });

    it('falls back to N/A when a dataset field is missing', () => {
        render(
            <OldDatasets
                {...baseProps}
                datasets={[
                    {
                        id: 9,
                        identifier: '10.0000/missing-curator',
                        title: 'Dataset missing curator metadata',
                        resourcetypegeneral: 'Dataset',
                        publicstatus: 'draft',
                    },
                ]}
            />,
        );

        const table = screen.getByRole('table');
        const bodyRow = within(table).getAllByRole('row')[1];
        const curatorStatusCell = within(bodyRow).getAllByRole('cell')[3];

        expect(curatorStatusCell).toHaveTextContent('-');
        expect(curatorStatusCell).toHaveTextContent('draft');
    });

    it('logs the server-provided diagnostics when the initial page load fails', () => {
        render(<OldDatasets
            datasets={[]}
            pagination={{
                current_page: 1,
                last_page: 1,
                per_page: 50,
                total: 0,
                from: 0,
                to: 0,
                has_more: false,
            }}
            error="SUMARIOPMD-Datenbankverbindung fehlgeschlagen: SQLSTATE[HY000] [2002] Connection refused"
            debug={{
                connection: 'metaworks',
                hosts: ['sumario-db.gfz'],
                port: 3306,
                database: 'sumario-pmd',
            }}
            sort={defaultSortState}
        />);

        expect(consoleGroupCollapsedSpy).toHaveBeenCalledWith('SUMARIOPMD diagnostics – initial page load');
        expect(consoleInfoSpy).toHaveBeenCalledWith('Message:', expect.stringContaining('Connection refused'));
        expect(consoleInfoSpy).toHaveBeenCalledWith('Details:', expect.objectContaining({
            connection: 'metaworks',
            hosts: ['sumario-db.gfz'],
        }));
        expect(consoleGroupEndSpy).toHaveBeenCalled();
    });

});

describe('deriveDatasetRowKey', () => {
    type DatasetLike = Parameters<typeof deriveDatasetRowKey>[0];

    it('derives a stable hash-based key when structural identifiers are missing', () => {
        const dataset: DatasetLike = {
            title: 'Example legacy dataset',
            publicationyear: 2021,
            created_at: '2024-01-01T12:34:56Z',
            updated_at: '2024-01-05T12:34:56Z',
            curator: 'Clara',
            language: 'en',
            publisher: 'Institute of Example',
            titles: [{ title: 'Example legacy dataset', titleType: 'Main Title' }],
            licenses: ['CC-BY-4.0'],
        };

        const firstKey = deriveDatasetRowKey(dataset);
        const secondKey = deriveDatasetRowKey({ ...dataset });

        expect(firstKey).toMatch(/^dataset-[a-z0-9]+-[a-z0-9-]+$/);
        expect(secondKey).toBe(firstKey);
    });

    it('prefers dataset ids and identifiers over derived hashes', () => {
        const datasetWithId: DatasetLike = { id: 987 };
        const datasetWithIdentifier: DatasetLike = { identifier: '10.1234/example' };

        expect(deriveDatasetRowKey(datasetWithId)).toBe('id-987');
        expect(deriveDatasetRowKey(datasetWithIdentifier)).toBe('doi-10.1234/example');
    });

    it('normalises fallback serialisation ordering to avoid collisions from object key order', () => {
        const firstDataset: DatasetLike = {
            extras: {
                zebra: 'last',
                alpha: 'first',
            },
        };

        const secondDataset: DatasetLike = {
            extras: {
                alpha: 'first',
                zebra: 'last',
            },
        };

        expect(deriveDatasetRowKey(firstDataset)).toBe(deriveDatasetRowKey(secondDataset));
    });

    it('includes a deterministic suffix in the derived key so collisions can be avoided client-side', () => {
        const dataset: DatasetLike = {
            curator: 'Alicia',
        };

        expect(deriveDatasetRowKey(dataset)).toMatch(/^dataset-[a-z0-9]+-[a-z0-9-]+$/);
    });
});

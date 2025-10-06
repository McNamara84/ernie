import { render, screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import OldDatasets, { deriveDatasetRowKey } from '@/pages/old-datasets';
import axios from 'axios';
import { vi, beforeEach, afterEach, describe, it, expect, type MockInstance } from 'vitest';

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

let activeIntersectionCallback: IntersectionObserverCallback = () => undefined;

class GlobalMockIntersectionObserver implements IntersectionObserver {
    readonly root: Element | Document | null = null;
    readonly rootMargin = '';
    readonly thresholds: ReadonlyArray<number> = [];

    constructor(callback: IntersectionObserverCallback) {
        activeIntersectionCallback = callback;
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

if (typeof globalThis.IntersectionObserver === 'undefined') {
    const mockObserver = GlobalMockIntersectionObserver as unknown as typeof IntersectionObserver;
    (globalThis as { IntersectionObserver: typeof IntersectionObserver }).IntersectionObserver = mockObserver;
    if (typeof window !== 'undefined') {
        (window as unknown as { IntersectionObserver: typeof IntersectionObserver }).IntersectionObserver = mockObserver;
    }
}

describe('OldDatasets page', () => {
    let observeSpy: ReturnType<typeof vi.fn>;
    let disconnectSpy: ReturnType<typeof vi.fn>;
    let consoleErrorSpy: MockInstance;
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
        activeIntersectionCallback = () => undefined;

        if (typeof window !== 'undefined' && window.localStorage) {
            window.localStorage.clear();
        }

        consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        consoleInfoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});

        if (typeof console.groupCollapsed !== 'function') {
            (console as unknown as { groupCollapsed: () => void }).groupCollapsed = () => {};
        }

        if (typeof console.groupEnd !== 'function') {
            (console as unknown as { groupEnd: () => void }).groupEnd = () => {};
        }

        consoleGroupCollapsedSpy = vi.spyOn(console, 'groupCollapsed').mockImplementation(() => {});
        consoleGroupEndSpy = vi.spyOn(console, 'groupEnd').mockImplementation(() => {});

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
        error: undefined,
        sort: defaultSortState,
    } as const;

    it('renders the legacy dataset overview with accessible labelling', () => {
        render(<OldDatasets {...baseProps} />);

        expect(screen.getByRole('heading', { name: 'Old Datasets', level: 1 })).toBeVisible();
        expect(screen.getByText('Overview of legacy resources from the SUMARIOPMD database')).toBeVisible();

        const badge = screen.getByText(/1-2 of 60 datasets/i);
        expect(badge).toBeVisible();

        const table = screen.getByRole('table');
        expect(table).toBeVisible();

        const headerRow = within(table).getAllByRole('row')[0];
        const idSortButton = within(headerRow).getByRole('button', {
            name: /Sort by the dataset ID from the legacy database/i,
        });
        expect(idSortButton).toHaveAttribute('aria-pressed', 'false');
        const identifierHeaderCell = idSortButton.closest('th');
        expect(identifierHeaderCell?.textContent).toContain('Identifier (DOI)');
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
        expect(within(firstRow).getByText('Published')).toBeVisible();
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
        expect(within(secondRow).getByText('Under Review')).toBeVisible();

        const titleCell = secondRowCells[1];
        expect(titleCell).toHaveTextContent(baseProps.datasets[0].title);
        expect(titleCell).toHaveClass('whitespace-normal');
        expect(titleCell).toHaveClass('break-words');

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
            expect(mockedAxios.get).toHaveBeenCalledWith('/old-datasets/load-more', {
                params: { page: 1, per_page: 20, sort_key: 'id', sort_direction: 'asc' },
            });
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
            expect(mockedAxios.get).toHaveBeenLastCalledWith('/old-datasets/load-more', {
                params: { page: 1, per_page: 20, sort_key: 'id', sort_direction: 'desc' },
            });
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

        mockedAxios.get.mockResolvedValueOnce({
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
            expect(mockedAxios.get).toHaveBeenCalledWith('/old-datasets/load-more', {
                params: { page: 1, per_page: 20, sort_key: 'created_at', sort_direction: 'asc' },
            });
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
            .mockRejectedValueOnce(axiosError)
            .mockResolvedValueOnce({
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

        const idSortButton = screen.getByRole('button', {
            name: /Sort by the dataset ID from the legacy database/i,
        });

        await user.click(idSortButton);

        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenNthCalledWith(1, '/old-datasets/load-more', {
                params: { page: 1, per_page: 20, sort_key: 'id', sort_direction: 'asc' },
            });
        });

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Failed to refresh datasets. Please try again.');
        expect(consoleGroupCollapsedSpy).toHaveBeenCalledWith('SUMARIOPMD diagnostics – sort change request');
        expect(consoleInfoSpy).toHaveBeenCalledWith('Details:', expect.objectContaining({
            connection: 'metaworks',
        }));

        const retryButton = within(alert).getByRole('button', { name: /retry/i });
        await user.click(retryButton);

        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenNthCalledWith(2, '/old-datasets/load-more', {
                params: { page: 1, per_page: 20, sort_key: 'id', sort_direction: 'asc' },
            });
        });

        await screen.findByText('Refreshed dataset after retry');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('provides accessible actions for opening datasets in the curation form', async () => {
        const user = userEvent.setup();

        render(<OldDatasets {...baseProps} />);

        const datasetOneButton = screen.getByRole('button', {
            name: /open dataset 10\.1234\/example-one in curation form/i,
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

        expect(routerGetSpy).toHaveBeenCalledWith(`/curation?${params.toString()}`);
    });

    it('omits the resource type when the identifier contains non-digit characters', async () => {
        const user = userEvent.setup();

        render(
            <OldDatasets
                {...baseProps}
                datasets={[
                    {
                        ...baseProps.datasets[0],
                        resourcetypegeneral: 'type123',
                    },
                ]}
            />,
        );

        const button = screen.getByRole('button', {
            name: /open dataset 10\.1234\/example-one in curation form/i,
        });

        await user.click(button);

        expect(routerGetSpy).toHaveBeenCalledTimes(1);
        const [requestedUrl] = routerGetSpy.mock.calls[0] as [string];
        const params = new URLSearchParams(requestedUrl.split('?')[1] ?? '');

        expect(params.has('resourceType')).toBe(false);
    });

    it('requests the next page when the sentinel row becomes visible', async () => {
        mockedAxios.get.mockResolvedValueOnce({
            data: {
                datasets: [
                    {
                        id: 3,
                        identifier: '10.5555/example-three',
                        title: 'Recently ingested dataset',
                        resourcetypegeneral: 'Text',
                        curator: 'Charlie',
                        created_at: '2024-03-01T09:30:00Z',
                        updated_at: '2024-03-01T12:00:00Z',
                        publicstatus: 'draft',
                        publisher: 'Example Publisher',
                        publicationyear: 2022,
                    },
                ],
                pagination: {
                    current_page: 2,
                    last_page: 3,
                    per_page: 20,
                    total: 60,
                    from: 21,
                    to: 40,
                    has_more: true,
                },
            },
        });

        render(<OldDatasets {...baseProps} />);

        const table = screen.getByRole('table');
        const bodyRows = within(table).getAllByRole('row').slice(1);
        const sentinelRow = bodyRows[bodyRows.length - 1];

        expect(observeSpy).toHaveBeenCalledWith(sentinelRow);

        activeIntersectionCallback([
            {
                isIntersecting: true,
                target: sentinelRow,
            } as IntersectionObserverEntry,
        ], {} as IntersectionObserver);

        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenCalledWith('/old-datasets/load-more', {
                params: { page: 2, per_page: 20, sort_key: 'updated_at', sort_direction: 'desc' },
            });
        });

        await screen.findByText('Recently ingested dataset');
        expect(screen.getByText(/1-3 of 60 datasets/i)).toBeVisible();
    });

    it('shows an inline retry affordance when loading additional pages fails', async () => {
        mockedAxios.get
            .mockRejectedValueOnce(new Error('network down'))
            .mockResolvedValueOnce({
                data: {
                    datasets: [
                        {
                            id: 3,
                            identifier: '10.5555/example-three',
                            title: 'Recovered dataset',
                            resourcetypegeneral: 'Software',
                            curator: 'Charlie',
                            created_at: '2024-03-01T09:30:00Z',
                            updated_at: '2024-03-01T12:00:00Z',
                            publicstatus: 'draft',
                            publisher: 'Example Publisher',
                            publicationyear: 2022,
                        },
                    ],
                    pagination: {
                        current_page: 2,
                        last_page: 2,
                        per_page: 20,
                        total: 3,
                        from: 21,
                        to: 21,
                        has_more: false,
                    },
                },
            });

        const user = userEvent.setup();

        render(<OldDatasets {...baseProps} />);

        const table = screen.getByRole('table');
        const bodyRows = within(table).getAllByRole('row').slice(1);
        const sentinelRow = bodyRows[bodyRows.length - 1];

        activeIntersectionCallback([
            {
                isIntersecting: true,
                target: sentinelRow,
            } as IntersectionObserverEntry,
        ], {} as IntersectionObserver);

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Failed to load more datasets. Please try again.');
        const retryButton = within(alert).getByRole('button', { name: /retry/i });

        await user.click(retryButton);

        await screen.findByText('Recovered dataset');

        await waitFor(() => {
            expect(mockedAxios.get).toHaveBeenCalledTimes(2);
        });

        expect(mockedAxios.get).toHaveBeenNthCalledWith(1, '/old-datasets/load-more', {
            params: { page: 2, per_page: 20, sort_key: 'updated_at', sort_direction: 'desc' },
        });
        expect(mockedAxios.get).toHaveBeenNthCalledWith(2, '/old-datasets/load-more', {
            params: { page: 2, per_page: 20, sort_key: 'updated_at', sort_direction: 'desc' },
        });

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByText(/All datasets have been loaded/i)).toBeVisible();
    });

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
        const curatorCell = within(bodyRow).getAllByRole('cell')[3];

        expect(curatorCell).toHaveTextContent('N/A');
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

    it('logs diagnostics that are returned with load more failures', async () => {
        const axiosError = Object.assign(new Error('Request failed with status code 500'), {
            isAxiosError: true,
            response: {
                data: {
                    error: 'Internal server error',
                    debug: {
                        connection: 'metaworks',
                        hosts: ['sumario-db.gfz'],
                        port: 3306,
                    },
                },
            },
        });

        mockedAxios.get.mockRejectedValueOnce(axiosError);

        render(<OldDatasets {...baseProps} />);

        const table = screen.getByRole('table');
        const bodyRows = within(table).getAllByRole('row').slice(1);
        const sentinelRow = bodyRows[bodyRows.length - 1];

        activeIntersectionCallback([
            {
                isIntersecting: true,
                target: sentinelRow,
            } as IntersectionObserverEntry,
        ], {} as IntersectionObserver);

        await waitFor(() => {
            expect(consoleGroupCollapsedSpy).toHaveBeenCalledWith('SUMARIOPMD diagnostics – load more request');
        });

        expect(mockedAxios.get).toHaveBeenCalledWith('/old-datasets/load-more', {
            params: { page: 2, per_page: 20, sort_key: 'updated_at', sort_direction: 'desc' },
        });
        expect(consoleInfoSpy).toHaveBeenCalledWith('Message:', 'Request failed with status code 500');
        expect(consoleInfoSpy).toHaveBeenCalledWith('Details:', expect.objectContaining({
            connection: 'metaworks',
            hosts: ['sumario-db.gfz'],
        }));
        expect(consoleErrorSpy).toHaveBeenCalledWith('Error loading more datasets:', axiosError);
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

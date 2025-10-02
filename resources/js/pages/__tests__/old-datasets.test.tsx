import { render, screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import OldDatasets from '../old-datasets';
import axios from 'axios';
import { vi, beforeEach, afterEach, describe, it, expect, type MockInstance } from 'vitest';

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
}));

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

        observeSpy = vi.fn();
        disconnectSpy = vi.fn();
        intersectionObserverHandlers.observe = (target: Element) => observeSpy(target);
        intersectionObserverHandlers.disconnect = () => disconnectSpy();
        intersectionObserverHandlers.unobserve = () => {};
        intersectionObserverHandlers.takeRecords = () => [];
        activeIntersectionCallback = () => undefined;

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
        expect(within(headerRow).getByText('Identifier (DOI)')).toBeVisible();
        const createdHeader = within(headerRow).getByText('Created');
        expect(createdHeader).toBeVisible();
        expect(createdHeader.parentElement?.textContent).toContain('Updated');
        const createdHeaderCell = createdHeader.closest('th');
        expect(createdHeaderCell).not.toBeNull();
        expect(createdHeaderCell).toHaveClass('min-w-[9rem]');
        expect(within(headerRow).queryByText('Created / Updated')).not.toBeInTheDocument();

        const bodyRows = within(table).getAllByRole('row').slice(1);
        expect(bodyRows).toHaveLength(2);

        const [firstRow, secondRow] = bodyRows;
        expect(within(firstRow).getByText('1')).toBeVisible();
        expect(within(firstRow).getByText(/10\.1234\/example-one/)).toBeVisible();
        expect(within(firstRow).getByText('Under Review')).toBeVisible();
        const titleCell = within(firstRow).getAllByRole('cell')[2];
        expect(titleCell).toHaveTextContent(baseProps.datasets[0].title);
        expect(titleCell).toHaveClass('whitespace-normal');
        expect(titleCell).toHaveClass('break-words');

        const createdUpdatedCell = within(firstRow).getAllByRole('cell')[5];
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

        expect(within(secondRow).getByText('2')).toBeVisible();
        expect(within(secondRow).getByText('Published')).toBeVisible();

        // Ensure the infinite scroll sentinel is observed for accessibility
        expect(observeSpy).toHaveBeenCalledTimes(1);
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
                params: { page: 2, per_page: 20 },
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
        const createdUpdatedCell = within(bodyRow).getAllByRole('cell')[5];
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
        const curatorCell = within(bodyRow).getAllByRole('cell')[4];

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

        expect(consoleInfoSpy).toHaveBeenCalledWith('Message:', 'Request failed with status code 500');
        expect(consoleInfoSpy).toHaveBeenCalledWith('Details:', expect.objectContaining({
            connection: 'metaworks',
            hosts: ['sumario-db.gfz'],
        }));
        expect(consoleErrorSpy).toHaveBeenCalledWith('Error loading more datasets:', axiosError);
    });
});

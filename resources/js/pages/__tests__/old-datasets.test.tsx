import { render, screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import OldDatasets from '../old-datasets';
import axios from 'axios';
import { vi, beforeEach, afterEach, describe, it, expect } from 'vitest';

const routerGetMock = vi.hoisted(() => vi.fn());

vi.mock('axios', () => {
    const get = vi.fn();
    return {
        default: { get },
        get,
    };
});

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: { get: routerGetMock },
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

    beforeEach(() => {
        mockedAxios.get.mockReset();
        routerGetMock.mockReset();

        observeSpy = vi.fn();
        disconnectSpy = vi.fn();
        intersectionObserverHandlers.observe = (target: Element) => observeSpy(target);
        intersectionObserverHandlers.disconnect = () => disconnectSpy();
        intersectionObserverHandlers.unobserve = () => {};
        intersectionObserverHandlers.takeRecords = () => [];
        activeIntersectionCallback = () => undefined;

        vi.spyOn(console, 'error').mockImplementation(() => {});
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
        expect(within(headerRow).getByText('Status')).toBeVisible();
        expect(within(headerRow).getByText('Actions')).toBeVisible();

        const bodyRows = within(table).getAllByRole('row').slice(1);
        expect(bodyRows).toHaveLength(2);

        const [firstRow, secondRow] = bodyRows;
        expect(within(firstRow).getByText('1')).toBeVisible();
        expect(within(firstRow).getByText(/10\.1234\/example-one/)).toBeVisible();
        expect(within(firstRow).getByText('Under Review')).toBeVisible();
        expect(within(firstRow).getByText(/01\/01\/2024/)).toBeVisible();
        expect(within(firstRow).getByText(/01\/02\/2024/)).toBeVisible();
        expect(within(firstRow).getByText(/A dataset title that is long enough/)).toHaveTextContent(/\.\.\.$/);

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

    it('opens the curation form with dataset details when the action button is pressed', async () => {
        mockedAxios.get.mockResolvedValueOnce({
            data: {
                id: 1,
                identifier: '10.1234/example-one',
                resourcetypegeneral: 'Dataset',
                publicationyear: 2024,
                version: '1.0',
                language: 'en',
                titles: [
                    { title: 'Main Title', titleType: 'main-title' },
                    { title: 'Second Title', titleType: 'alternative-title' },
                ],
            },
        });

        const user = userEvent.setup();
        render(<OldDatasets {...baseProps} />);

        const actionButton = screen.getByRole('button', {
            name: /open dataset 10\.1234\/example-one with ernie/i,
        });

        await user.click(actionButton);

        expect(mockedAxios.get).toHaveBeenCalledWith('/old-datasets/1');
        expect(routerGetMock).toHaveBeenCalledTimes(1);

        const calledUrl = routerGetMock.mock.calls[0][0];
        expect(calledUrl).toMatch(/^\/curation\?/);
        const params = new URLSearchParams(calledUrl.split('?')[1]);
        expect(params.get('doi')).toBe('10.1234/example-one');
        expect(params.get('year')).toBe('2024');
        expect(params.get('version')).toBe('1.0');
        expect(params.get('language')).toBe('en');
        expect(params.get('resourceTypeSlug')).toBe('dataset');
        expect(params.get('titles[0][title]')).toBe('Main Title');
        expect(params.get('titles[0][titleType]')).toBe('main-title');
        expect(params.get('titles[1][title]')).toBe('Second Title');
        expect(params.get('titles[1][titleType]')).toBe('alternative-title');
    });

    it('shows an error message when the dataset cannot be opened in ERNIE', async () => {
        mockedAxios.get.mockRejectedValueOnce(new Error('network'));

        const user = userEvent.setup();
        render(<OldDatasets {...baseProps} />);

        const actionButton = screen.getByRole('button', {
            name: /open dataset 10\.1234\/example-one with ernie/i,
        });

        await user.click(actionButton);

        await waitFor(() => {
            const alerts = screen.getAllByRole('alert');
            expect(alerts.length).toBeGreaterThan(0);
            expect(alerts[alerts.length - 1]).toHaveTextContent(
                /could not open the dataset in ernie/i,
            );
        });
        expect(routerGetMock).not.toHaveBeenCalled();
    });
});

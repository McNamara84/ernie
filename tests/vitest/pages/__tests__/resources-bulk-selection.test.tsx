import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ResourcesPage from '@/pages/resources';

const routerMock = vi.hoisted(() => ({
    get: vi.fn(),
    delete: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));

const editorRouteMock = vi.hoisted(() =>
    vi.fn(({ query }: { query?: Record<string, string> } = {}) => ({
        url: query ? `/editor?${new URLSearchParams(query).toString()}` : '/editor',
        method: 'get',
    })),
);

const axiosPostMock = vi.hoisted(() => vi.fn());
const axiosGetMock = vi.hoisted(() => vi.fn());
const toastMock = vi.hoisted(() =>
    Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
);

vi.mock('sonner', () => ({ toast: toastMock }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
    usePage: () => ({
        props: {
            auth: {
                user: {
                    can_manage_landing_pages: true,
                    can_register_production_doi: true,
                },
            },
        },
    }),
}));

vi.mock('@/routes', () => ({ editor: editorRouteMock }));

vi.mock('@/lib/curation-query', () => ({
    buildCurationQueryFromResource: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return {
        ...actual,
        default: {
            ...actual.default,
            post: axiosPostMock,
            get: axiosGetMock,
        },
        post: axiosPostMock,
        get: axiosGetMock,
    };
});

const buildResource = (overrides: Partial<Record<string, unknown>>) => ({
    id: 1,
    doi: '10.9999/one',
    year: 2024,
    version: '1.0',
    created_at: '2024-04-01T09:00:00Z',
    updated_at: '2024-04-02T10:00:00Z',
    resourcetypegeneral: 'Dataset',
    title: 'First',
    first_author: { givenName: 'A', familyName: 'B' },
    curator: 'Curator',
    publicstatus: 'curation',
    ...overrides,
});

const buildProps = () => ({
    resources: [
        buildResource({ id: 1, doi: '10.9999/one', title: 'First' }),
        buildResource({ id: 2, doi: '10.9999/two', title: 'Second' }),
        buildResource({ id: 3, doi: null, title: 'Third' }),
    ],
    pagination: {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 3,
        from: 1,
        to: 3,
        has_more: false,
    },
    sort: { key: 'id' as const, direction: 'asc' as const },
});

describe('ResourcesPage - bulk selection', () => {
    let originalCreateObjectURL: typeof URL.createObjectURL | undefined;
    let originalRevokeObjectURL: typeof URL.revokeObjectURL | undefined;
    let createObjectUrlMock: ReturnType<typeof vi.fn>;
    let revokeObjectUrlMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        routerMock.post.mockClear();
        routerMock.reload.mockClear();
        axiosPostMock.mockReset();
        axiosGetMock.mockReset();
        toastMock.mockClear();
        toastMock.success.mockClear();
        toastMock.error.mockClear();
        toastMock.warning.mockClear();

        // jsdom does not implement URL.createObjectURL / revokeObjectURL.
        // Save the original descriptors (if any) so afterEach can restore them,
        // preventing test leakage into unrelated specs.
        const createDescriptor = Object.getOwnPropertyDescriptor(URL, 'createObjectURL');
        const revokeDescriptor = Object.getOwnPropertyDescriptor(URL, 'revokeObjectURL');
        originalCreateObjectURL = createDescriptor ? (URL.createObjectURL as typeof URL.createObjectURL) : undefined;
        originalRevokeObjectURL = revokeDescriptor ? (URL.revokeObjectURL as typeof URL.revokeObjectURL) : undefined;

        createObjectUrlMock = vi.fn().mockReturnValue('blob:mock');
        revokeObjectUrlMock = vi.fn();
        Object.defineProperty(URL, 'createObjectURL', {
            value: createObjectUrlMock,
            configurable: true,
            writable: true,
        });
        Object.defineProperty(URL, 'revokeObjectURL', {
            value: revokeObjectUrlMock,
            configurable: true,
            writable: true,
        });
    });

    afterEach(() => {
        document.head.innerHTML = '';

        // Restore the original URL methods so later specs see the pristine jsdom state.
        if (originalCreateObjectURL === undefined) {
            delete (URL as { createObjectURL?: typeof URL.createObjectURL }).createObjectURL;
        } else {
            Object.defineProperty(URL, 'createObjectURL', {
                value: originalCreateObjectURL,
                configurable: true,
                writable: true,
            });
        }
        if (originalRevokeObjectURL === undefined) {
            delete (URL as { revokeObjectURL?: typeof URL.revokeObjectURL }).revokeObjectURL;
        } else {
            Object.defineProperty(URL, 'revokeObjectURL', {
                value: originalRevokeObjectURL,
                configurable: true,
                writable: true,
            });
        }
    });

    it('renders a select-all checkbox and per-row checkboxes', () => {
        render(<ResourcesPage {...buildProps()} />);

        expect(screen.getByTestId('resources-select-all')).toBeInTheDocument();
        expect(screen.getByTestId('resources-row-checkbox-1')).toBeInTheDocument();
        expect(screen.getByTestId('resources-row-checkbox-2')).toBeInTheDocument();
        expect(screen.getByTestId('resources-row-checkbox-3')).toBeInTheDocument();
    });

    it('shows the toolbar idle hint when no rows are selected', () => {
        render(<ResourcesPage {...buildProps()} />);

        expect(screen.getByText(/select rows to enable bulk actions/i)).toBeInTheDocument();
    });

    it('updates selected count when toggling a row checkbox', () => {
        render(<ResourcesPage {...buildProps()} />);

        const rowCheckbox = screen.getByTestId('resources-row-checkbox-2');
        fireEvent.click(rowCheckbox);

        expect(screen.getByText(/^1 resource selected$/i)).toBeInTheDocument();
    });

    it('selects every visible row when the header checkbox is clicked', () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-select-all'));

        expect(screen.getByText(/^3 resources selected$/i)).toBeInTheDocument();
    });

    it('posts selected ids to the batch-register endpoint', async () => {
        axiosPostMock.mockResolvedValue({
            data: { success: [{ id: 1, doi: '10.9999/one', updated: true }], failed: [] },
        });

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('bulk-register-button'));

        // Wait for click handler to fire
        await Promise.resolve();
        await Promise.resolve();

        expect(axiosPostMock).toHaveBeenCalledWith('/resources/batch-register', { ids: [1] });
    });

    it('posts selected ids and chosen format to the batch-export endpoint', async () => {
        axiosPostMock.mockResolvedValue({
            data: new Blob(['zip'], { type: 'application/zip' }),
            headers: { 'content-disposition': 'attachment; filename="resources-export-datacite-xml.zip"' },
        });

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-3'));

        // Open dropdown via userEvent (Radix relies on pointer events)
        await userEvent.click(screen.getByTestId('bulk-export-button'));
        await userEvent.click(await screen.findByRole('menuitem', { name: /DataCite XML/i }));

        expect(axiosPostMock).toHaveBeenCalledWith(
            '/resources/batch-export',
            { ids: expect.arrayContaining([2, 3]), format: 'datacite-xml' },
            { responseType: 'blob' },
        );
        expect(createObjectUrlMock).toHaveBeenCalled();
    });

    it('renders the import button alongside the bulk toolbar', () => {
        const props = { ...buildProps(), canImportFromDataCite: true };
        render(<ResourcesPage {...props} />);

        expect(within(screen.getByTestId('app-layout')).getByRole('button', { name: /import from datacite/i })).toBeInTheDocument();
    });

    it('places the curator/created columns inside hidden responsive containers', () => {
        render(<ResourcesPage {...buildProps()} />);
        const created = screen.getByRole('columnheader', { name: /created/i });
        // The header's TH carries the responsive hidden classes via cellClassName
        expect(created.className).toMatch(/hidden/);
    });

    it('clears selection and reloads after a successful bulk register', async () => {
        axiosPostMock.mockResolvedValue({
            data: {
                success: [{ id: 1, doi: '10.9999/one', updated: true }],
                failed: [],
            },
        });

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('bulk-register-button'));

        // flush microtasks for awaited axios + state updates
        await new Promise((r) => setTimeout(r, 0));

        expect(toastMock.success).toHaveBeenCalledWith(expect.stringMatching(/registered\/updated/i));
        expect(routerMock.reload).toHaveBeenCalledWith({ only: ['resources', 'pagination'] });
    });

    it('reports failures from a 200 response with a `failed` array', async () => {
        axiosPostMock.mockResolvedValue({
            data: {
                success: [],
                failed: [{ id: 1, doi: '10.9999/one', reason: 'No landing page configured' }],
            },
        });

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('bulk-register-button'));

        await new Promise((r) => setTimeout(r, 0));

        expect(toastMock.error).toHaveBeenCalledWith(expect.stringContaining('No landing page configured'));
    });

    it('handles a 207 partial-success error response from axios', async () => {
        const error = Object.assign(new Error('Multi-Status'), {
            isAxiosError: true,
            response: {
                status: 207,
                data: {
                    success: [{ id: 1, doi: '10.9999/one', updated: true }],
                    failed: [{ id: 2, doi: '10.9999/two', reason: 'IGSN resources must use IGSN endpoint' }],
                },
            },
        });
        axiosPostMock.mockRejectedValue(error);

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
        fireEvent.click(screen.getByTestId('bulk-register-button'));

        await new Promise((r) => setTimeout(r, 0));

        expect(toastMock.success).toHaveBeenCalledWith(expect.stringMatching(/registered\/updated/i));
        expect(toastMock.error).toHaveBeenCalledWith(expect.stringContaining('IGSN'));
        expect(routerMock.reload).toHaveBeenCalledWith({ only: ['resources', 'pagination'] });
    });

    it('shows a generic error toast when bulk register fails for an unexpected reason', async () => {
        axiosPostMock.mockRejectedValue(new Error('boom'));

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('bulk-register-button'));

        await new Promise((r) => setTimeout(r, 0));

        expect(toastMock.error).toHaveBeenCalledWith('Bulk registration failed');
    });

    it('falls back to a synthesized filename when content-disposition is missing', async () => {
        axiosPostMock.mockResolvedValue({
            data: new Blob(['zip'], { type: 'application/zip' }),
            headers: {},
        });

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await userEvent.click(screen.getByTestId('bulk-export-button'));
        await userEvent.click(await screen.findByRole('menuitem', { name: /DataCite JSON$/i }));

        expect(createObjectUrlMock).toHaveBeenCalled();
        expect(toastMock.success).toHaveBeenCalledWith(expect.stringContaining('DATACITE-JSON'));
    });

    it('shows an error toast when bulk export fails', async () => {
        axiosPostMock.mockRejectedValue(new Error('network down'));

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await userEvent.click(screen.getByTestId('bulk-export-button'));
        await userEvent.click(await screen.findByRole('menuitem', { name: /DataCite JSON-LD/i }));

        expect(toastMock.error).toHaveBeenCalledWith('Bulk export failed');
    });

    it('does not call the API when bulk register is invoked with no selection', () => {
        render(<ResourcesPage {...buildProps()} />);

        // Button is disabled while no selection — clicking has no effect
        fireEvent.click(screen.getByTestId('bulk-register-button'));

        expect(axiosPostMock).not.toHaveBeenCalled();
    });

    it('toggles a row off when its checkbox is clicked twice', () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        expect(screen.getByText(/^1 resource selected$/i)).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        expect(screen.getByText(/select rows to enable bulk actions/i)).toBeInTheDocument();
    });

    it('clears the selection when the header checkbox is unchecked after a select-all', () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-select-all'));
        expect(screen.getByText(/^3 resources selected$/i)).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('resources-select-all'));
        expect(screen.getByText(/select rows to enable bulk actions/i)).toBeInTheDocument();
    });
});

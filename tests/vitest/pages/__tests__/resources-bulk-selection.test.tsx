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
    beforeEach(() => {
        routerMock.post.mockClear();
        routerMock.reload.mockClear();
        axiosPostMock.mockReset();
        axiosGetMock.mockReset();
    });

    afterEach(() => {
        document.head.innerHTML = '';
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

        // Mock URL.createObjectURL since jsdom doesn't provide it
        const createUrl = vi.fn().mockReturnValue('blob:mock');
        const revokeUrl = vi.fn();
        Object.defineProperty(window.URL, 'createObjectURL', { value: createUrl, writable: true });
        Object.defineProperty(window.URL, 'revokeObjectURL', { value: revokeUrl, writable: true });

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
});

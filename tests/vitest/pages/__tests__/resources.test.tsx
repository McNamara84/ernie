import '@testing-library/jest-dom/vitest';

import { act,fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ResourcesPage, { buildDoiUrl, formatDateTime, getPrimaryTitle } from '@/pages/resources';

const routerMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }));
const buildCurationQueryFromResourceMock = vi.hoisted(() => vi.fn());
const editorRouteMock = vi.hoisted(
    () =>
        vi.fn(
            ({ query }: { query?: Record<string, string> } = {}) => ({
                url: query
                    ? `/editor?${new URLSearchParams(query).toString()}`
                    : '/editor',
                method: 'get',
            }),
        ),
);

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
}));

vi.mock('@/lib/curation-query', () => ({
    buildCurationQueryFromResource: buildCurationQueryFromResourceMock,
}));

vi.mock('@/routes', () => ({
    editor: editorRouteMock,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

describe('resource helper utilities', () => {
    it('selects the main title when available and falls back otherwise', () => {
        const titles = [
            { title: 'Secondary title', title_type: { name: 'Subtitle', slug: 'subtitle' } },
            { title: 'Primary', title_type: { name: 'Main Title', slug: 'main-title' } },
        ];

        expect(getPrimaryTitle(titles as never)).toBe('Primary');
        expect(getPrimaryTitle([{ title: 'Only title', title_type: null }] as never)).toBe('Only title');
        expect(getPrimaryTitle([] as never)).toBe('Untitled resource');
    });

    it('builds DOI URLs safely', () => {
        expect(buildDoiUrl('10.1234/abc')).toBe('https://doi.org/10.1234/abc');
        expect(buildDoiUrl('  ')).toBeNull();
        expect(buildDoiUrl(null)).toBeNull();
    });

    it('formats date time information with ISO output', () => {
        const result = formatDateTime('2024-01-01T10:00:00Z');
        expect(result.iso).toBe('2024-01-01T10:00:00.000Z');
        expect(result.label).toBeTruthy();
        expect(formatDateTime(null).label).toBe('Not available');
    });
});

describe('ResourcesPage', () => {
    beforeEach(() => {
        routerMock.get.mockClear();
        routerMock.delete.mockClear();
        buildCurationQueryFromResourceMock.mockReset();
        buildCurationQueryFromResourceMock.mockResolvedValue({});
        editorRouteMock.mockClear();
    });

    afterEach(() => {
        document.head.innerHTML = '';
    });

    it('renders a table with the streamlined dataset overview', () => {
        const props = {
            resources: [
                {
                    id: 1,
                    doi: '10.9999/example',
                    year: 2024,
                    version: '2.0',
                    created_at: '2024-04-01T09:00:00Z',
                    updated_at: '2024-04-02T10:00:00Z',
                    resource_type: { name: 'Dataset', slug: 'dataset' },
                    language: { name: 'English', code: 'en' },
                    titles: [
                        { title: 'Primary title', title_type: { name: 'Main', slug: 'main-title' } },
                        { title: 'Alternate', title_type: { name: 'Subtitle', slug: 'subtitle' } },
                    ],
                    licenses: [
                        { name: 'CC-BY 4.0', identifier: 'cc-by-4.0' },
                    ],
                    authors: [],
                },
            ],
            pagination: {
                current_page: 1,
                last_page: 3,
                per_page: 25,
                total: 60,
                from: 1,
                to: 25,
                has_more: true,
            },
        } as const;

        buildCurationQueryFromResourceMock.mockResolvedValue({
            resourceId: '1',
            doi: '10.9999/example',
        });

        render(<ResourcesPage {...props} />);

        expect(screen.getByTestId('app-layout')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1, name: /resources/i })).toBeInTheDocument();
        expect(screen.getByText(/showing 1–25 of 60 resources/i)).toBeInTheDocument();

        const table = screen.getByRole('table');
        expect(table).toBeInTheDocument();
        const columnDefinitions = table.querySelectorAll('colgroup col');
        expect(columnDefinitions.length).toBeGreaterThan(0);
        expect(columnDefinitions[0]).toHaveClass('w-[18rem]');
        expect(within(table).getByText('Primary title')).toBeInTheDocument();
        expect(within(table).getByRole('columnheader', { name: /id\s+doi/i })).toBeInTheDocument();
        expect(within(table).queryByText(/show additional titles/i)).not.toBeInTheDocument();
        expect(within(table).queryByText('Dataset')).not.toBeInTheDocument();
        expect(within(table).queryByText('English (EN)')).not.toBeInTheDocument();
        expect(within(table).queryByText('CC-BY 4.0')).not.toBeInTheDocument();
        expect(within(table).queryByText(/version/i)).not.toBeInTheDocument();
        const dataRows = within(table).getAllByRole('row').slice(1);
        expect(within(dataRows[0]).getByText('1')).toBeInTheDocument();
        expect(
            within(dataRows[0]).getByRole('link', { name: /10\.9999\/example/ })
        ).toHaveAttribute('href', 'https://doi.org/10.9999/example');
        expect(
            within(dataRows[0]).queryByText((content) => content.trim() === 'DOI')
        ).not.toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: /actions/i })).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /edit primary title in the editor/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /delete primary title from ernie/i }),
        ).toBeInTheDocument();

        const timeElements = within(table).getAllByText((_, element) => element?.tagName === 'TIME');
        expect(timeElements[0]).toHaveAttribute('dateTime', '2024-04-01T09:00:00.000Z');
        expect(timeElements[1]).toHaveAttribute('dateTime', '2024-04-02T10:00:00.000Z');
    });

    it('shows a friendly empty state when there are no resources', () => {
        render(
            <ResourcesPage
                resources={[]}
                pagination={{ current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, has_more: false }}
            />,
        );

        expect(screen.getByText(/no resources found/i)).toBeInTheDocument();
        expect(screen.getByText(/will appear in this list/i)).toBeInTheDocument();
    });

    it('navigates between pages using inertia router', () => {
        const props = {
            resources: [
                {
                    id: 1,
                    doi: null,
                    year: 2024,
                    version: null,
                    created_at: null,
                    updated_at: null,
                    resource_type: null,
                    language: null,
                    titles: [{ title: 'Untitled', title_type: null }],
                    licenses: [],
                    authors: [],
                },
            ],
            pagination: {
                current_page: 2,
                last_page: 4,
                per_page: 25,
                total: 80,
                from: 26,
                to: 50,
                has_more: true,
            },
        } as const;

        render(<ResourcesPage {...props} />);

        const previousButton = screen.getByRole('button', { name: /previous/i });
        const nextButton = screen.getByRole('button', { name: /next/i });

        expect(previousButton).toBeEnabled();
        expect(nextButton).toBeEnabled();

        expect(screen.getByText('Not registered yet')).toBeInTheDocument();

        fireEvent.click(nextButton);
        expect(routerMock.get).toHaveBeenCalledWith(
            '/resources',
            { page: 3, per_page: 25 },
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );

        fireEvent.click(previousButton);
        expect(routerMock.get).toHaveBeenCalledWith(
            '/resources',
            { page: 1, per_page: 25 },
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });

    it('uses a friendly placeholder when a resource has no DOI', () => {
        const props = {
            resources: [
                {
                    id: 99,
                    doi: null,
                    year: 2023,
                    version: null,
                    created_at: null,
                    updated_at: null,
                    resource_type: null,
                    language: null,
                    titles: [{ title: 'Placeholder title', title_type: null }],
                    licenses: [],
                    authors: [],
                },
            ],
            pagination: {
                current_page: 1,
                last_page: 1,
                per_page: 25,
                total: 1,
                from: 1,
                to: 1,
                has_more: false,
            },
        } as const;

        render(<ResourcesPage {...props} />);

        const dataRows = screen.getAllByRole('row').slice(1);
        expect(within(dataRows[0]).getByText('Not registered yet')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /delete placeholder title from ernie/i }));

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText((content) => content.includes('Not registered yet')),
        ).toBeInTheDocument();
    });

    it('opens the curation editor with prefilled metadata when the action is triggered', async () => {
        const resource = {
            id: 1,
            doi: '10.9999/example',
            year: 2024,
            version: '2.0',
            created_at: '2024-04-01T09:00:00Z',
            updated_at: '2024-04-02T10:00:00Z',
            resource_type: { name: 'Dataset', slug: 'dataset' },
            language: { name: 'English', code: 'en' },
            titles: [
                { title: 'Primary title', title_type: { name: 'Main', slug: 'main-title' } },
            ],
            licenses: [{ name: 'CC-BY 4.0', identifier: 'cc-by-4.0' }],
            authors: [],
        } as const;

        buildCurationQueryFromResourceMock.mockResolvedValue({
            doi: resource.doi,
            resourceId: String(resource.id),
        });

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 25,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
            />,
        );

        const editButton = screen.getByRole('button', {
            name: /edit primary title in the editor/i,
        });

        await act(async () => {
            fireEvent.click(editButton);
            await Promise.resolve();
        });

        expect(buildCurationQueryFromResourceMock).toHaveBeenCalledWith(resource);
        expect(editorRouteMock).toHaveBeenCalledWith({
            query: { doi: resource.doi, resourceId: String(resource.id) },
        });
        const lastCall = routerMock.get.mock.calls.at(-1);
        expect(lastCall?.[0]).toBe('/editor?doi=10.9999%2Fexample&resourceId=1');
    });

    it('confirms destructive actions before deleting a resource', async () => {
        const resource = {
            id: 1,
            doi: '10.5555/example',
            year: 2022,
            version: null,
            created_at: null,
            updated_at: null,
            resource_type: null,
            language: null,
            titles: [{ title: 'Primary title', title_type: null }],
            licenses: [],
        } as const;

        render(
            <ResourcesPage
                resources={[resource as never]}
                pagination={{
                    current_page: 1,
                    last_page: 1,
                    per_page: 25,
                    total: 1,
                    from: 1,
                    to: 1,
                    has_more: false,
                }}
            />,
        );

        const deleteButton = screen.getByRole('button', {
            name: /delete primary title from ernie/i,
        });

        await act(async () => {
            fireEvent.click(deleteButton);
            await Promise.resolve();
        });

        expect(
            screen.getByRole('dialog', {
                name: /delete “primary title”\?/i,
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(/this will permanently remove/i)).toBeInTheDocument();

        const confirmButton = screen.getByRole('button', { name: /delete resource/i });

        expect(confirmButton).toBeEnabled();

        await act(async () => {
            fireEvent.click(confirmButton);
            await Promise.resolve();
        });

        expect(routerMock.delete).toHaveBeenCalledWith(
            '/resources/1',
            expect.objectContaining({
                preserveScroll: true,
            }),
        );

        expect(confirmButton).toBeDisabled();
        expect(confirmButton).toHaveAttribute('aria-busy', 'true');

        const [, options] = routerMock.delete.mock.calls.at(-1) ?? [];

        expect(options?.onSuccess).toBeTypeOf('function');
        expect(options?.onFinish).toBeTypeOf('function');

        await act(async () => {
            options?.onSuccess?.();
            options?.onFinish?.();
        });

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});

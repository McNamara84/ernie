import '@testing-library/jest-dom/vitest';
import { render, screen, within, fireEvent, act } from '@testing-library/react';
import ResourcesPage, {
    buildDoiUrl,
    describeLanguage,
    describeLicense,
    describeResourceType,
    formatDateTime,
    getAdditionalTitles,
    getPrimaryTitle,
} from '@/pages/resources';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const routerMock = vi.hoisted(() => ({ get: vi.fn() }));
const buildCurationQueryFromResourceMock = vi.hoisted(() => vi.fn());
const curationRouteMock = vi.hoisted(
    () =>
        vi.fn(
            ({ query }: { query?: Record<string, string> } = {}) => ({
                url: query
                    ? `/curation?${new URLSearchParams(query).toString()}`
                    : '/curation',
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
    curation: curationRouteMock,
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

    it('derives additional titles by excluding the primary entry', () => {
        const titles = [
            { title: 'Main', title_type: { name: 'Main', slug: 'main-title' } },
            { title: 'Alt', title_type: { name: 'Alternate', slug: 'alternative-title' } },
        ];

        const extras = getAdditionalTitles(titles as never);
        expect(extras).toHaveLength(1);
        expect(extras[0]?.title).toBe('Alt');
        expect(getAdditionalTitles([{ title: 'Single', title_type: null }] as never)).toHaveLength(0);
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

    it('describes metadata fields for accessibility', () => {
        expect(describeLanguage({ name: 'English', code: 'en' })).toBe('English (EN)');
        expect(describeLanguage({ name: null, code: 'fr' })).toBe('fr');
        expect(describeLanguage(null)).toBe('Not specified');

        expect(describeResourceType({ name: 'Dataset', slug: 'dataset' })).toBe('Dataset');
        expect(describeResourceType(null)).toBe('Not classified');

        expect(describeLicense({ name: 'CC-BY', identifier: 'cc-by' })).toBe('CC-BY');
        expect(describeLicense({ name: null, identifier: 'cc0' })).toBe('cc0');
    });
});

describe('ResourcesPage', () => {
    beforeEach(() => {
        routerMock.get.mockClear();
        buildCurationQueryFromResourceMock.mockReset();
        buildCurationQueryFromResourceMock.mockResolvedValue({});
        curationRouteMock.mockClear();
    });

    afterEach(() => {
        document.head.innerHTML = '';
    });

    it('renders a table with resource information and accessibility enhancements', () => {
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

        buildCurationQueryFromResourceMock.mockResolvedValue({ doi: '10.9999/example' });

        render(<ResourcesPage {...props} />);

        expect(screen.getByTestId('app-layout')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1, name: /resources/i })).toBeInTheDocument();
        expect(screen.getByText(/showing 1–25 of 60 resources/i)).toBeInTheDocument();

        const table = screen.getByRole('table');
        expect(table).toBeInTheDocument();
        expect(within(table).getByText('Primary title')).toBeInTheDocument();
        const summary = within(table).getByText(/show additional titles/i);
        expect(summary.tagName).toBe('SUMMARY');
        expect(within(table).getByText('Dataset')).toBeInTheDocument();
        expect(within(table).getByText('English (EN)')).toBeInTheDocument();
        expect(within(table).getByText('CC-BY 4.0')).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: /actions/i })).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /edit primary title in the curation editor/i }),
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
        } as const;

        buildCurationQueryFromResourceMock.mockResolvedValue({ doi: resource.doi });

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
            name: /edit primary title in the curation editor/i,
        });

        await act(async () => {
            fireEvent.click(editButton);
            await Promise.resolve();
        });

        expect(buildCurationQueryFromResourceMock).toHaveBeenCalledWith(resource);
        expect(curationRouteMock).toHaveBeenCalledWith({ query: { doi: resource.doi } });
        const lastCall = routerMock.get.mock.calls.at(-1);
        expect(lastCall?.[0]).toBe('/curation?doi=10.9999%2Fexample');
    });
});

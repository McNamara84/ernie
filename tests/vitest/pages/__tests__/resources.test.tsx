import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ResourcesPage from '@/pages/resources';

const routerMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn() }));
const axiosGetMock = vi.hoisted(() => vi.fn());
const buildCurationQueryFromResourceMock = vi.hoisted(() => vi.fn());
const editorRouteMock = vi.hoisted(() =>
    vi.fn(({ query }: { query?: Record<string, string | number> } = {}) => ({
        url: query ? `/editor?${new URLSearchParams(Object.entries(query).map(([key, value]) => [key, String(value)])).toString()}` : '/editor',
        method: 'get',
    })),
);

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
    usePage: () => ({
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Test User',
                    email: 'test@example.test',
                    font_size_preference: 'regular',
                    email_verified_at: null,
                    created_at: '2024-01-01T00:00:00Z',
                    updated_at: '2024-01-01T00:00:00Z',
                    role: 'group_leader',
                    can_manage_landing_pages: true,
                    can_register_production_doi: true,
                },
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

vi.mock('@/utils/filter-parser', () => ({
    parseResourceFiltersFromUrl: vi.fn().mockReturnValue({}),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('@/components/resources-filters', () => ({
    ResourcesFilters: () => <div data-testid="resources-filters" />,
}));
vi.mock('@/components/landing-pages/modals/SetupLandingPageModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/ImportFromDataCiteModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/ImportSingleOldResourceModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/RegisterDoiModal', () => ({ default: () => null }));
vi.mock('@/components/citations/CitationManagerModal', () => ({ CitationManagerModal: () => null }));
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
    let openMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        routerMock.get.mockClear();
        routerMock.delete.mockClear();
        routerMock.reload.mockClear();
        routerMock.visit.mockClear();
        axiosGetMock.mockReset();
        axiosGetMock.mockResolvedValue({ data: {} });
        buildCurationQueryFromResourceMock.mockReset();
        buildCurationQueryFromResourceMock.mockResolvedValue({});
        editorRouteMock.mockClear();
        originalOpen = window.open;
        openMock = vi.fn().mockReturnValue({ closed: false });
        Object.defineProperty(window, 'open', {
            value: openMock,
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

    afterEach(() => {
        document.head.innerHTML = '';
        Object.defineProperty(window, 'open', {
            value: originalOpen,
            configurable: true,
            writable: true,
        });
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
        expect(openMock).toHaveBeenCalledWith('/editor?resourceId=1', '_blank', 'noopener,noreferrer');
        expect(buildCurationQueryFromResourceMock).not.toHaveBeenCalled();
        expect(routerMock.get).not.toHaveBeenCalled();
    });
});

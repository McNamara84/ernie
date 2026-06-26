import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const routerMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
const axiosGetMock = vi.hoisted(() => vi.fn());
const toastMock = vi.hoisted(() => Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn(), warning: vi.fn() }));
const editorRouteMock = vi.hoisted(() =>
    vi.fn(({ query }: { query?: Record<string, string | number> } = {}) => ({
        url: query ? `/editor?${new URLSearchParams(Object.entries(query).map(([key, value]) => [key, String(value)])).toString()}` : '/editor',
        method: 'get',
    })),
);
const mockUser = vi.hoisted(() => ({
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
    can_access_old_datasets: false,
    can_access_statistics: false,
    can_access_users: false,
    can_access_logs: false,
    can_access_editor_settings: false,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
    usePage: () => ({ props: { auth: { user: mockUser } } }),
}));

vi.mock('axios', () => ({
    default: {
        get: axiosGetMock,
    },
    get: axiosGetMock,
    isAxiosError: (err: unknown) => Boolean(err && typeof err === 'object' && 'isAxiosError' in err),
}));
vi.mock('sonner', () => ({ toast: toastMock }));
vi.mock('@/lib/blob-utils', () => ({
    extractErrorMessageFromBlob: vi.fn().mockResolvedValue('Error'),
    parseValidationErrorFromBlob: vi.fn().mockResolvedValue(null),
}));
vi.mock('@/lib/curation-query', () => ({
    buildCurationQueryFromResource: vi.fn().mockResolvedValue({}),
}));
vi.mock('@/routes', () => ({ editor: editorRouteMock }));
vi.mock('@/utils/filter-parser', () => ({
    parseResourceFiltersFromUrl: vi.fn().mockReturnValue({}),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));
vi.mock('@/components/resources-filters', () => ({
    ResourcesFilters: () => <div data-testid="resources-filters" />,
}));
vi.mock('@/components/landing-pages/modals/SetupLandingPageModal', () => ({
    default: ({ isOpen, resource }: { isOpen: boolean; resource: { id: number; title?: string } }) =>
        isOpen ? <div data-testid="setup-landing-page-modal">Setup landing page for {resource.title ?? resource.id}</div> : null,
}));
vi.mock('@/components/resources/modals/RegisterDoiModal', () => ({
    default: ({ isOpen, resource }: { isOpen: boolean; resource: { id: number; title?: string } }) =>
        isOpen ? <div data-testid="register-doi-modal">Register DOI for {resource.title ?? resource.id}</div> : null,
}));
vi.mock('@/components/resources/modals/ImportFromDataCiteModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/ImportSingleOldResourceModal', () => ({ default: () => null }));
vi.mock('@/components/ui/validation-error-modal', () => ({ ValidationErrorModal: () => null }));
vi.mock('@/components/citations/CitationManagerModal', () => ({
    CitationManagerModal: ({ open, resourceId }: { open: boolean; resourceId: number }) =>
        open ? <div data-testid="citation-manager-modal">Related items for {resourceId}</div> : null,
}));
vi.mock('@/hooks/use-citation-vocabularies', () => ({
    useCitationVocabularies: () => ({
        vocabularies: { resourceTypes: [], relationTypes: [], contributorTypes: [] },
        isLoading: false,
    }),
}));

import ResourcesPage, { deriveResourceRowKey } from '@/pages/resources';
import { DEFAULT_RESOURCE_COLUMN_WIDTHS } from '@/pages/resources-column-widths';

interface TestResource {
    id: number;
    doi?: string | null;
    year: number;
    version?: string | null;
    created_at?: string;
    updated_at?: string;
    curator?: string;
    publicstatus?: string;
    resourcetypegeneral?: string;
    title?: string;
    first_author?: { givenName?: string | null; familyName?: string | null; name?: string } | null;
    landingPage?: { id: number; is_published: boolean; public_url: string } | null;
    [key: string]: unknown;
}

const landingPage = { id: 1, is_published: false, public_url: 'https://example.test/preview' };

function makeResource(overrides: Partial<TestResource> = {}): TestResource {
    return {
        id: 1,
        doi: '10.5880/test.2024.001',
        year: 2024,
        version: '1.0',
        created_at: '2024-01-15T10:30:00Z',
        updated_at: '2024-06-20T14:00:00Z',
        curator: 'Test Curator',
        publicstatus: 'published',
        resourcetypegeneral: 'Dataset',
        title: 'Test Dataset Title',
        first_author: { givenName: 'John', familyName: 'Doe' },
        landingPage,
        ...overrides,
    };
}

function makePagination(overrides = {}) {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 1,
        from: 1,
        to: 1,
        has_more: false,
        ...overrides,
    };
}

const defaultSort = { key: 'updated_at' as const, direction: 'desc' as const };

const createLocalStorageMock = (): Storage => {
    let store: Record<string, string> = {};

    return {
        clear: vi.fn(() => {
            store = {};
        }),
        getItem: vi.fn((key: string) => store[key] ?? null),
        key: vi.fn((index: number) => Object.keys(store)[index] ?? null),
        removeItem: vi.fn((key: string) => {
            delete store[key];
        }),
        setItem: vi.fn((key: string, value: string) => {
            store[key] = String(value);
        }),
        get length() {
            return Object.keys(store).length;
        },
    } as Storage;
};

const localStorageMock = createLocalStorageMock();
Object.defineProperty(window, 'localStorage', { value: localStorageMock, configurable: true });
Object.defineProperty(globalThis, 'localStorage', { value: localStorageMock, configurable: true });
function renderPage(propsOverrides: Record<string, unknown> = {}) {
    const props = {
        resources: [makeResource()],
        pagination: makePagination(),
        sort: defaultSort,
        ...propsOverrides,
    };
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    return render(<ResourcesPage {...(props as any)} />);
}

async function renderPageReady(propsOverrides: Record<string, unknown> = {}) {
    const result = renderPage(propsOverrides);
    await screen.findByTestId('resources-filters');
    return result;
}

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

const ensureResourceActionsMenuOpen = async () => {
    if (screen.queryByRole('menu')) {
        return;
    }

    await openResourceActionsMenu();
};

describe('ResourcesPage - extended', () => {
    let originalOpen: typeof window.open;
    let openMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.clear();
        axiosGetMock.mockResolvedValue({ data: {} });
        Object.assign(mockUser, {
            role: 'group_leader',
            can_manage_landing_pages: true,
            can_register_production_doi: true,
            can_access_old_datasets: false,
            can_access_statistics: false,
            can_access_users: false,
            can_access_logs: false,
            can_access_editor_settings: false,
        });
        Object.defineProperty(window, 'location', { writable: true, value: { href: '', search: '' } });
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

    describe('deriveResourceRowKey', () => {
        it('uses id when available', () => {
            expect(deriveResourceRowKey({ id: 42, year: 2024 } as never)).toBe('resource-id-42');
        });

        it('uses doi when id is missing', () => {
            expect(deriveResourceRowKey({ id: undefined as never, doi: '10.5880/test', year: 2024 } as never)).toBe('resource-doi-10.5880/test');
        });

        it('falls back to metadata segments when both id and doi are missing', () => {
            const key = deriveResourceRowKey({
                id: undefined as never,
                doi: null,
                title: 'My Title',
                year: 2024,
                created_at: '2024-01-01',
            } as never);
            expect(key).toContain('resource-');
            expect(key).toContain('my-title');
            expect(key).toContain('2024');
        });
    });

    describe('sort UI', () => {
        it('renders sort buttons for each column sort option', async () => {
            await renderPageReady();

            expect(screen.getByRole('button', { name: /sort by the resource id/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /sort by the doi/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /sort by the resource title/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /sort by the resource type/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /sort by the first author/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /sort by the publication year/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /sort by the curator name/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /sort by the publication status/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /sort by the created date/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /sort by the updated date/i })).toBeInTheDocument();
        });

        it('shows sort badge reflecting current sort state', async () => {
            await renderPageReady();
            expect(screen.getByText(/sorted by: updated date descending/i)).toBeInTheDocument();
        });

        it('shows ascending copy for ascending sort', async () => {
            await renderPageReady({ sort: { key: 'id', direction: 'asc' } });
            expect(screen.getByText(/sorted by: id ascending/i)).toBeInTheDocument();
        });

        it('marks active and inactive sort buttons correctly', () => {
            renderPage();
            expect(screen.getByRole('button', { name: /sort by the updated date.*currently sorted/i })).toHaveAttribute('aria-pressed', 'true');
            expect(screen.getByRole('button', { name: /sort by the resource id/i })).toHaveAttribute('aria-pressed', 'false');
        });

        it('navigates with sort params when sort button is clicked', () => {
            renderPage();
            fireEvent.click(screen.getByRole('button', { name: /sort by the resource id/i }));
            expect(routerMock.visit).toHaveBeenCalledWith(expect.stringContaining('sort_key=id'), expect.any(Object));
        });
    });

    describe('resource overview layout', () => {
        it('renders resource type below ID and DOI above title in row cells', () => {
            renderPage({
                resources: [makeResource({ id: 7, doi: '10.5880/compact-layout', title: 'Compact Layout Resource' })],
            });

            const table = screen.getByRole('table');
            const dataRow = within(table).getAllByRole('row')[1];
            const cells = within(dataRow).getAllByRole('cell');

            expect(Array.from(cells[1].querySelectorAll('span')).map((span) => span.textContent)).toEqual(['#7', 'Dataset']);
            expect(Array.from(cells[2].querySelectorAll('span')).map((span) => span.textContent)).toEqual([
                '10.5880/compact-layout',
                'Compact Layout Resource',
            ]);
        });

        it('keeps the regrouped columns compact and resizable', () => {
            renderPage();

            expect(screen.getByTestId('resources-column-id_resourcetype')).toHaveStyle({
                width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.id_resourcetype}px`,
            });
            expect(screen.getByTestId('resources-column-doi_title')).toHaveStyle({
                width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px`,
            });
            expect(screen.getByRole('separator', { name: /resize id and resource type column/i })).toBeInTheDocument();
            expect(screen.getByRole('separator', { name: /resize doi and title column/i })).toBeInTheDocument();
        });
    });

    describe('metadata formatting', () => {
        it('formats created and updated dates via time elements', () => {
            renderPage({ resources: [makeResource({ created_at: '2024-03-10T08:00:00Z', updated_at: '2024-09-25T15:00:00Z' })] });
            const datetimeValues = Array.from(document.querySelectorAll('time')).map((time) => time.getAttribute('datetime'));
            expect(datetimeValues.some((value) => value?.includes('2024-03-10'))).toBe(true);
            expect(datetimeValues.some((value) => value?.includes('2024-09-25'))).toBe(true);
        });

        it('formats personal and institutional authors', () => {
            const { rerender } = renderPage({ resources: [makeResource({ first_author: { familyName: 'Smith', givenName: 'Alice' } })] });
            expect(screen.getByText('Smith, Alice')).toBeInTheDocument();

            rerender(
                <ResourcesPage
                    resources={[makeResource({ first_author: { name: 'GFZ Potsdam' } })] as never}
                    pagination={makePagination()}
                    sort={defaultSort}
                />,
            );
            expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
        });

        it('renders status labels and clickable DOI/preview badges', () => {
            renderPage({ resources: [makeResource({ publicstatus: 'published', doi: '10.5880/test.2024.001' })] });
            expect(screen.getByText('Published')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /published.*click to open doi/i })).toBeInTheDocument();
        });

        it('renders review badge as preview link when a landing page URL exists', () => {
            renderPage({ resources: [makeResource({ publicstatus: 'review', landingPage })] });
            expect(screen.getByText('Review')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /review.*click to open preview/i })).toBeInTheDocument();
        });
    });

    describe('imports and permissions', () => {
        it('shows import buttons when canImportFromDataCite is true', () => {
            renderPage({ canImportFromDataCite: true });
            expect(screen.getByRole('button', { name: /import all old resources/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /import old single resource/i })).toBeInTheDocument();
        });

        it('hides import buttons when canImportFromDataCite is false', () => {
            renderPage({ canImportFromDataCite: false });
            expect(screen.queryByRole('button', { name: /import all old resources/i })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /import old single resource/i })).not.toBeInTheDocument();
        });

        it('shows or hides landing page action based on permission', async () => {
            mockUser.can_manage_landing_pages = true;
            const { rerender } = renderPage();
            fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
            await openResourceActionsMenu();
            expect(screen.getByTestId('resources-action-setup-landing-page')).toBeInTheDocument();

            mockUser.can_manage_landing_pages = false;
            rerender(<ResourcesPage resources={[makeResource()] as never} pagination={makePagination()} sort={defaultSort} />);
            await ensureResourceActionsMenuOpen();
            expect(screen.queryByTestId('resources-action-setup-landing-page')).not.toBeInTheDocument();
        });

        it('hides DataCite actions when registration permission is missing', async () => {
            mockUser.can_register_production_doi = false;
            renderPage();
            fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
            await openResourceActionsMenu();

            expect(screen.queryByTestId('resources-action-register-doi')).not.toBeInTheDocument();
            expect(screen.queryByTestId('resources-action-update-metadata')).not.toBeInTheDocument();
        });
    });

    describe('toolbar actions', () => {
        it('opens DOI registration for one DOI-less resource with a landing page', async () => {
            renderPage({ resources: [makeResource({ id: 2, doi: null, title: 'New Resource', publicstatus: 'draft', landingPage })] });

            fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
            await clickResourceAction('resources-action-register-doi');

            expect(screen.getByTestId('register-doi-modal')).toHaveTextContent('New Resource');
        });

        it('explains when DOI registration is blocked by a missing landing page', async () => {
            renderPage({ resources: [makeResource({ id: 2, doi: null, title: 'New Resource', publicstatus: 'draft', landingPage: null })] });

            fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
            await clickResourceAction('resources-action-register-doi');

            expect(toastMock.error).toHaveBeenCalledWith('A landing page must be set up before registering a DOI.');
            expect(screen.queryByTestId('register-doi-modal')).not.toBeInTheDocument();
        });

        it('enables update metadata for registered resources with landing pages', async () => {
            renderPage({ resources: [makeResource()] });

            fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
            await openResourceActionsMenu();

            expect(screen.getByTestId('resources-action-update-metadata')).not.toHaveAttribute('aria-disabled');
            expect(screen.getByTestId('resources-action-register-doi')).toHaveAttribute('data-unavailable', 'true');
            expect(screen.getByTestId('resources-action-register-doi')).not.toHaveAttribute('aria-disabled');
        });

        it('renders export actions in the action menu', async () => {
            renderPage();
            fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
            await openResourceActionsMenu();

            expect(screen.getByTestId('resources-action-export-datacite-json')).toBeInTheDocument();
            expect(screen.getByTestId('resources-action-export-datacite-xml')).toBeInTheDocument();
            expect(screen.getByTestId('resources-action-export-jsonld')).toBeInTheDocument();
        });

        it('enables delete for selected non-published resources', async () => {
            renderPage({ resources: [makeResource({ id: 5, doi: null, publicstatus: 'draft', landingPage: null })] });

            fireEvent.click(screen.getByTestId('resources-row-checkbox-5'));
            await openResourceActionsMenu();
            const deleteButton = screen.getByTestId('resources-action-delete');

            expect(deleteButton).not.toHaveAttribute('aria-disabled');
            await userEvent.click(deleteButton);
            expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        });

        it('keeps published resources out of the submitted delete request', async () => {
            renderPage({ resources: [makeResource({ id: 5, publicstatus: 'published', landingPage })] });

            fireEvent.click(screen.getByTestId('resources-row-checkbox-5'));
            await clickResourceAction('resources-action-delete');

            expect(screen.getByText(/no selected resources can be deleted/i)).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /delete 0 resources/i })).toBeDisabled();
            expect(routerMock.delete).not.toHaveBeenCalled();
        });

        it('submits the batch delete request for draft resources', async () => {
            renderPage({ resources: [makeResource({ id: 5, doi: null, publicstatus: 'draft', landingPage: null })] });

            fireEvent.click(screen.getByTestId('resources-row-checkbox-5'));
            await clickResourceAction('resources-action-delete');
            await userEvent.click(screen.getByRole('button', { name: /^delete resource$/i }));

            expect(routerMock.delete).toHaveBeenCalledWith('/resources/batch', expect.objectContaining({ data: { ids: [5] }, preserveScroll: true }));
        });

        it('hides delete action when the user lacks delete permission', async () => {
            mockUser.role = 'beginner';
            renderPage({ resources: [makeResource({ id: 5, doi: null, publicstatus: 'draft', landingPage: null })] });
            fireEvent.click(screen.getByTestId('resources-row-checkbox-5'));
            await openResourceActionsMenu();

            expect(screen.queryByTestId('resources-action-delete')).not.toBeInTheDocument();
        });

        it('opens the editor from the selected edit action', async () => {
            renderPage();

            fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
            await clickResourceAction('resources-action-edit');

            expect(editorRouteMock).toHaveBeenCalledWith({ query: { resourceId: 1 } });
            expect(openMock).toHaveBeenCalledWith('/editor?resourceId=1', '_blank', 'noopener,noreferrer');
        });
    });

    describe('sync and persistence', () => {
        it('syncs refreshed resources and pagination props into local state after reloads', () => {
            const initialProps = {
                resources: [makeResource({ id: 1, title: 'Old Resource Title', doi: '10.5880/test.2024.001' })],
                pagination: makePagination({ total: 1, to: 1 }),
                sort: defaultSort,
                canImportFromDataCite: true,
            };

            const { rerender } = render(<ResourcesPage {...initialProps} />);

            expect(screen.getByText('Old Resource Title')).toBeInTheDocument();
            expect(screen.getByText(/all resources have been loaded.*1 total/i)).toBeInTheDocument();

            rerender(
                <ResourcesPage
                    {...initialProps}
                    resources={[makeResource({ id: 2, title: 'Imported Resource Title', doi: '10.5880/test.2024.002' })]}
                    pagination={makePagination({ total: 2, to: 1 })}
                />,
            );

            expect(screen.queryByText('Old Resource Title')).not.toBeInTheDocument();
            expect(screen.getByText('Imported Resource Title')).toBeInTheDocument();
            expect(screen.getByText(/all resources have been loaded.*2 total/i)).toBeInTheDocument();
        });

        it('writes sort state to localStorage', async () => {
            renderPage({ sort: { key: 'title', direction: 'asc' } });

            await waitFor(() => {
                const stored = JSON.parse(localStorage.getItem('resources.sort-preference')!);
                expect(stored).toEqual({ key: 'title', direction: 'asc' });
            });
        });

        it('reads valid sort from localStorage on mount', async () => {
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'doi', direction: 'asc' }));
            renderPage();

            await waitFor(() => {
                expect(screen.getByText(/sorted by: doi ascending/i)).toBeInTheDocument();
            });
        });
    });
});

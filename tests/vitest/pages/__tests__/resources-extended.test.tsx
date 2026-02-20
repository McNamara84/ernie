import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Hoisted mocks
const routerMock = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
const editorRouteMock = vi.hoisted(() =>
    vi.fn(({ query }: { query?: Record<string, string> } = {}) => ({
        url: query ? `/editor?${new URLSearchParams(query).toString()}` : '/editor',
        method: 'get',
    })),
);
const mockUser = vi.hoisted(() => ({
    can_manage_landing_pages: true,
    can_access_old_datasets: false,
    can_access_statistics: false,
    can_access_users: false,
    can_access_logs: false,
    can_access_editor_settings: false,
}));

// Core Inertia mock
vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
    usePage: () => ({ props: { auth: { user: mockUser } } }),
}));

vi.mock('@/lib/curation-query', () => ({
    buildCurationQueryFromResource: vi.fn().mockResolvedValue({}),
}));
vi.mock('@/routes', () => ({ editor: editorRouteMock }));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

// Additional mocks for full component rendering
vi.mock('axios', () => ({
    default: { get: vi.fn().mockResolvedValue({ data: {} }) },
    isAxiosError: (err: unknown) => !!(err && typeof err === 'object' && 'isAxiosError' in err),
}));
vi.mock('sonner', () => {
    const t = Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn(), warning: vi.fn() });
    return { toast: t };
});
vi.mock('@/lib/blob-utils', () => ({
    extractErrorMessageFromBlob: vi.fn().mockResolvedValue('Error'),
    parseValidationErrorFromBlob: vi.fn().mockResolvedValue(null),
}));
vi.mock('@/utils/filter-parser', () => ({
    parseResourceFiltersFromUrl: vi.fn().mockReturnValue({}),
}));
vi.mock('@/components/landing-pages/modals/SetupLandingPageModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/ImportFromDataCiteModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/RegisterDoiModal', () => ({ default: () => null }));
vi.mock('@/components/ui/validation-error-modal', () => ({ ValidationErrorModal: () => null }));
vi.mock('@/components/resources-filters', () => ({
    ResourcesFilters: () => <div data-testid="resources-filters" />,
}));

import ResourcesPage, { deriveResourceRowKey } from '@/pages/resources';

// ---------------------------------------------------------------------------
// Helper factories
// ---------------------------------------------------------------------------

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
    landingPage?: { id: number; status: string; public_url: string } | null;
}

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
        landingPage: null,
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

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('ResourcesPage – extended', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.clear();
        Object.defineProperty(window, 'location', { writable: true, value: { href: '', search: '' } });

        // IntersectionObserver stub – does not auto-trigger
        global.IntersectionObserver = vi.fn().mockImplementation(() => ({
            observe: vi.fn(),
            unobserve: vi.fn(),
            disconnect: vi.fn(),
            root: null,
            rootMargin: '',
            thresholds: [],
            takeRecords: vi.fn(() => []),
        })) as unknown as typeof IntersectionObserver;
    });

    afterEach(() => {
        document.head.innerHTML = '';
    });

    // ── deriveResourceRowKey (exported pure function) ──────────────────
    describe('deriveResourceRowKey', () => {
        it('uses id when available', () => {
            expect(deriveResourceRowKey({ id: 42, year: 2024 } as never)).toBe('resource-id-42');
        });

        it('uses doi when id is missing', () => {
            expect(
                deriveResourceRowKey({ id: undefined as never, doi: '10.5880/test', year: 2024 } as never),
            ).toBe('resource-doi-10.5880/test');
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

    // ── Sort UI ───────────────────────────────────────────────────────
    describe('sort UI', () => {
        it('renders sort buttons for each column sort option', () => {
            renderPage();
            // The resources page has sort options: ID, DOI, Title, Type, Author, Year, Curator, Status, Created, Updated
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

        it('shows sort badge reflecting current sort state', () => {
            renderPage();
            const badge = screen.getByText(/sorted by/i);
            expect(badge).toHaveTextContent(/updated date/i);
            expect(badge).toHaveTextContent('↓');
        });

        it('shows ascending arrow for ascending sort', () => {
            renderPage({ sort: { key: 'id', direction: 'asc' } });
            const badge = screen.getByText(/sorted by/i);
            expect(badge).toHaveTextContent(/\bid\b/i);
            expect(badge).toHaveTextContent('↑');
        });

        it('marks active sort button as pressed', () => {
            renderPage();
            const updatedButton = screen.getByRole('button', { name: /sort by the updated date.*currently sorted/i });
            expect(updatedButton).toHaveAttribute('aria-pressed', 'true');
        });

        it('marks inactive sort buttons as not pressed', () => {
            renderPage();
            const idButton = screen.getByRole('button', { name: /sort by the resource id/i });
            expect(idButton).toHaveAttribute('aria-pressed', 'false');
        });

        it('navigates with sort params when sort button is clicked', async () => {
            renderPage();
            const idButton = screen.getByRole('button', { name: /sort by the resource id/i });
            await act(async () => {
                fireEvent.click(idButton);
            });
            expect(routerMock.visit).toHaveBeenCalledWith(
                expect.stringContaining('sort_key=id'),
                expect.any(Object),
            );
        });
    });

    // ── Date formatting ──────────────────────────────────────────────
    describe('date columns', () => {
        it('formats created and updated dates via <time> elements', () => {
            renderPage({
                resources: [makeResource({ created_at: '2024-03-10T08:00:00Z', updated_at: '2024-09-25T15:00:00Z' })],
            });
            const times = document.querySelectorAll('time');
            expect(times.length).toBeGreaterThanOrEqual(2);
            // One for created, one for updated
            const datetimeValues = Array.from(times).map((t) => t.getAttribute('datetime'));
            expect(datetimeValues.some((d) => d?.includes('2024-03-10'))).toBe(true);
            expect(datetimeValues.some((d) => d?.includes('2024-09-25'))).toBe(true);
        });

        it('shows dash for missing dates', () => {
            renderPage({
                resources: [makeResource({ created_at: undefined, updated_at: undefined })],
            });
            // Two dashes should appear in the date column area
            const table = screen.getByRole('table');
            const textContent = table.textContent ?? '';
            // At least two dashes for created/updated
            expect(textContent.match(/-/g)!.length).toBeGreaterThanOrEqual(2);
        });
    });

    // ── Author formatting ────────────────────────────────────────────
    describe('author formatting', () => {
        it('formats familyName and givenName', () => {
            renderPage({ resources: [makeResource({ first_author: { familyName: 'Smith', givenName: 'Alice' } })] });
            expect(screen.getByText('Smith, Alice')).toBeInTheDocument();
        });

        it('shows only familyName when givenName is missing', () => {
            renderPage({ resources: [makeResource({ first_author: { familyName: 'Smith' } })] });
            expect(screen.getByText('Smith')).toBeInTheDocument();
        });

        it('shows institutional name from name field', () => {
            renderPage({ resources: [makeResource({ first_author: { name: 'GFZ Potsdam' } })] });
            expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
        });

        it('shows dash for missing author', () => {
            renderPage({ resources: [makeResource({ first_author: null })] });
            const table = screen.getByRole('table');
            const rows = within(table).getAllByRole('row');
            const dataRow = rows[1]; // skip header
            // The author cell shows '-'
            expect(within(dataRow).getAllByText('-').length).toBeGreaterThan(0);
        });
    });

    // ── Status display ───────────────────────────────────────────────
    describe('status badges', () => {
        it('renders published status label', () => {
            renderPage({ resources: [makeResource({ publicstatus: 'published' })] });
            expect(screen.getByText('Published')).toBeInTheDocument();
        });

        it('renders review status label', () => {
            renderPage({ resources: [makeResource({ publicstatus: 'review' })] });
            expect(screen.getByText('Review')).toBeInTheDocument();
        });

        it('renders curation status label', () => {
            renderPage({ resources: [makeResource({ publicstatus: 'curation' })] });
            expect(screen.getByText('Curation')).toBeInTheDocument();
        });

        it('published badge is clickable when DOI exists', () => {
            renderPage({ resources: [makeResource({ publicstatus: 'published', doi: '10.5880/test.2024.001' })] });
            const badge = screen.getByRole('button', { name: /published.*click to open doi/i });
            expect(badge).toBeInTheDocument();
        });

        it('review badge is clickable when landing page URL exists', () => {
            renderPage({
                resources: [
                    makeResource({
                        publicstatus: 'review',
                        landingPage: { id: 1, status: 'active', public_url: 'https://example.com/preview' },
                    }),
                ],
            });
            const badge = screen.getByRole('button', { name: /review.*click to open preview/i });
            expect(badge).toBeInTheDocument();
        });
    });

    // ── Error display ────────────────────────────────────────────────
    describe('error states', () => {
        it('shows error prop message', () => {
            renderPage({ error: 'Database connection failed' });
            expect(screen.getByText('Database connection failed')).toBeInTheDocument();
        });

        it('shows filter-qualified empty state when resources list is empty', () => {
            renderPage({ resources: [], pagination: makePagination({ total: 0, from: 0, to: 0 }) });
            expect(screen.getByText(/no resources found matching your filters/i)).toBeInTheDocument();
        });
    });

    // ── Import from DataCite ─────────────────────────────────────────
    describe('Import from DataCite button', () => {
        it('shows Import button when canImportFromDataCite is true', () => {
            renderPage({ canImportFromDataCite: true });
            expect(screen.getByRole('button', { name: /import from datacite/i })).toBeInTheDocument();
        });

        it('hides Import button when canImportFromDataCite is false', () => {
            renderPage({ canImportFromDataCite: false });
            expect(screen.queryByRole('button', { name: /import from datacite/i })).not.toBeInTheDocument();
        });
    });

    // ── Landing page management button ───────────────────────────────
    describe('landing page management', () => {
        it('shows landing page setup button when user has permission', () => {
            mockUser.can_manage_landing_pages = true;
            renderPage({ resources: [makeResource()] });
            expect(screen.getByRole('button', { name: /setup landing page/i })).toBeInTheDocument();
        });

        it('hides landing page button when user lacks permission', () => {
            mockUser.can_manage_landing_pages = false;
            renderPage({ resources: [makeResource()] });
            expect(screen.queryByRole('button', { name: /setup landing page/i })).not.toBeInTheDocument();
        });
    });

    // ── DOI registration icon ────────────────────────────────────────
    describe('DOI registration button', () => {
        it('shows DataCite button when resource has a landing page', () => {
            renderPage({
                resources: [
                    makeResource({ landingPage: { id: 1, status: 'active', public_url: 'https://example.com' } }),
                ],
            });
            expect(screen.getByTestId('datacite-button')).toBeInTheDocument();
        });

        it('does not show DataCite button when resource has no landing page', () => {
            renderPage({ resources: [makeResource({ landingPage: null })] });
            expect(screen.queryByTestId('datacite-button')).not.toBeInTheDocument();
        });
    });

    // ── Action buttons ───────────────────────────────────────────────
    describe('action buttons', () => {
        it('renders export JSON and XML buttons for each resource', () => {
            renderPage();
            expect(screen.getByRole('button', { name: /export resource.*as datacite json/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /export resource.*as datacite xml/i })).toBeInTheDocument();
        });

        it('renders disabled delete button', () => {
            renderPage();
            const deleteBtn = screen.getByRole('button', { name: /delete resource.*not yet implemented/i });
            expect(deleteBtn).toBeDisabled();
        });

        it('opens editor when edit button is clicked', async () => {
            renderPage();
            const editButton = screen.getByRole('button', { name: /open resource.*in.*editor/i });
            await act(async () => {
                fireEvent.click(editButton);
                await Promise.resolve();
            });
            expect(editorRouteMock).toHaveBeenCalledWith({ query: { resourceId: 1 } });
            expect(routerMock.get).toHaveBeenCalled();
        });
    });

    // ── All loaded message ───────────────────────────────────────────
    describe('completion message', () => {
        it('shows all-loaded message when has_more is false', () => {
            renderPage({ pagination: makePagination({ has_more: false, total: 3 }) });
            expect(screen.getByText(/all resources have been loaded.*3 total/i)).toBeInTheDocument();
        });
    });

    // ── ResourcesFilters presence ────────────────────────────────────
    describe('filters', () => {
        it('renders the filters component', () => {
            renderPage();
            expect(screen.getByTestId('resources-filters')).toBeInTheDocument();
        });
    });

    // ── localStorage sort preference ─────────────────────────────────
    describe('sort preference persistence', () => {
        it('writes sort state to localStorage', () => {
            renderPage({ sort: { key: 'title', direction: 'asc' } });
            const stored = JSON.parse(localStorage.getItem('resources.sort-preference')!);
            expect(stored).toEqual({ key: 'title', direction: 'asc' });
        });

        it('reads valid sort from localStorage on mount', () => {
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'doi', direction: 'asc' }));
            renderPage();
            // The badge should reflect the localStorage preference
            expect(screen.getByText(/sorted by: doi/i)).toBeInTheDocument();
        });

        it('ignores invalid sort in localStorage', () => {
            localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'invalid', direction: 'asc' }));
            renderPage();
            // Falls back to the sort prop
            expect(screen.getByText(/sorted by: updated date/i)).toBeInTheDocument();
        });
    });

    // ── Multiple resources rendering ─────────────────────────────────
    describe('multiple resources', () => {
        it('renders all resources in the table', () => {
            renderPage({
                resources: [
                    makeResource({ id: 1, title: 'First Resource', doi: '10.5880/first' }),
                    makeResource({ id: 2, title: 'Second Resource', doi: '10.5880/second' }),
                    makeResource({ id: 3, title: 'Third Resource', doi: null }),
                ],
                pagination: makePagination({ total: 3, to: 3 }),
            });
            expect(screen.getByText('First Resource')).toBeInTheDocument();
            expect(screen.getByText('Second Resource')).toBeInTheDocument();
            expect(screen.getByText('Third Resource')).toBeInTheDocument();
            expect(screen.getByText('Not registered')).toBeInTheDocument();
        });
    });
});

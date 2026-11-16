import { beforeEach, vi } from 'vitest';

import ResourcesPage from '@/pages/resources';
import { COLUMN_WIDTH_STORAGE_KEY, DEFAULT_RESOURCE_COLUMN_WIDTHS } from '@/pages/resources-column-widths';

import { commands, expect, page, render, test } from '../helpers/test';

const routerMock = vi.hoisted(() => ({ delete: vi.fn(), get: vi.fn(), reload: vi.fn(), visit: vi.fn() }));
const axiosGetMock = vi.hoisted(() => vi.fn());
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
    can_register_doi: true,
    can_register_production_doi: true,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    router: routerMock,
    usePage: () => ({ props: { auth: { user: mockUser } } }),
}));

vi.mock('axios', () => ({
    default: { get: axiosGetMock },
    get: axiosGetMock,
    isAxiosError: (err: unknown) => Boolean(err && typeof err === 'object' && 'isAxiosError' in err),
}));

vi.mock('sonner', () => ({
    toast: {
        error: vi.fn(),
        success: vi.fn(),
        warning: vi.fn(),
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('@/components/resources-filters', () => ({
    ResourcesFilters: () => <div data-testid="resources-filters" />,
}));

vi.mock('@/components/landing-pages/modals/SetupLandingPageModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/RegisterDoiModal', () => ({ default: () => null }));
vi.mock('@/components/citations/CitationManagerModal', () => ({
    CitationManagerModal: ({ open, onOpenChange, resourceId }: { open: boolean; onOpenChange: (open: boolean) => void; resourceId: number }) =>
        open ? (
            <div data-testid="citation-manager-modal">
                Related items for {resourceId}
                <button type="button" onClick={() => onOpenChange(false)}>
                    Close citation manager
                </button>
            </div>
        ) : null,
}));
vi.mock('@/components/ui/validation-error-modal', () => ({ ValidationErrorModal: () => null }));
vi.mock('@/hooks/use-citation-vocabularies', () => ({
    useCitationVocabularies: () => ({
        vocabularies: { contributorTypes: [], relationTypes: [], resourceTypes: [] },
        isLoading: false,
    }),
}));
vi.mock('@/routes', () => ({
    editor: vi.fn(() => ({ method: 'get', url: '/editor' })),
}));
vi.mock('@/utils/filter-parser', () => ({
    parseResourceFiltersFromUrl: vi.fn().mockReturnValue({}),
}));

const resource = {
    id: 1,
    doi: '10.5880/test.2026.001',
    year: 2026,
    created_at: '2026-01-01T10:00:00Z',
    updated_at: '2026-02-01T10:00:00Z',
    curator: 'Column Curator',
    publicstatus: 'published',
    resourcetypegeneral: 'Dataset',
    title: 'A very long resource title that should be truncated when the DOI title column is narrowed',
    first_author: { familyName: 'Longlastname', givenName: 'Longfirstname' },
    landingPage: { id: 1, is_published: true, public_url: 'https://example.test/resource' },
};

const pagination = {
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 1,
    from: 1,
    to: 1,
    has_more: false,
};

beforeEach(() => {
    axiosGetMock.mockResolvedValue({ data: {} });
});

async function renderPage() {
    return render(<ResourcesPage resources={[resource]} pagination={pagination} sort={{ key: 'id', direction: 'asc' }} />);
}

test('resizes an actual table column with a pointer drag and persists its width', async () => {
    await renderPage();
    const handle = page.getByRole('separator', { name: /resize doi and title column/i });
    const column = page.getByTestId('resources-column-doi_title');
    const before = column.element().getBoundingClientRect().width;
    await commands.dragBy(handle, 120, 0);
    const expected = DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title + 120;
    await expect.element(handle).toHaveAttribute('aria-valuenow', String(expected));
    expect(column.element().getBoundingClientRect().width).toBeGreaterThan(before);
    expect(JSON.parse(localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY) ?? '{}').doi_title).toBe(expected);
});

test('resizes with the keyboard while the real separator owns focus', async ({ user }) => {
    await renderPage();
    const handle = page.getByRole('separator', { name: /resize doi and title column/i });
    handle.element().focus();
    await user.keyboard('{ArrowRight}');
    await expect.element(handle).toHaveAttribute('aria-valuenow', String(DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title + 16));
    await user.keyboard('{Home}');
    expect(JSON.parse(localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY) ?? '{}').doi_title).toBe(220);
    await expect.element(handle).toHaveFocus();
});

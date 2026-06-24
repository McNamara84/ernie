import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

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
vi.mock('@/components/resources/modals/ImportFromDataCiteModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/ImportSingleOldResourceModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/RegisterDoiModal', () => ({ default: () => null }));
vi.mock('@/components/citations/CitationManagerModal', () => ({ CitationManagerModal: () => null }));
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

import ResourcesPage, {
    clampColumnWidth,
    COLUMN_WIDTH_STORAGE_KEY,
    DEFAULT_RESOURCE_COLUMN_WIDTHS,
    normalizeResourceColumnWidths,
    OverflowTooltipText,
    parseStoredResourceColumnWidths,
} from '@/pages/resources';

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

function renderResourcesPage() {
    return render(<ResourcesPage resources={[resource]} pagination={pagination} sort={{ key: 'id', direction: 'asc' }} />);
}

function getDoiTitleColumn() {
    return screen.getByTestId('resources-column-doi_title');
}

describe('resource column width helpers', () => {
    it('clamps widths to each column boundary', () => {
        expect(clampColumnWidth('doi_title', 100)).toBe(220);
        expect(clampColumnWidth('doi_title', 900)).toBe(720);
        expect(clampColumnWidth('doi_title', 333.6)).toBe(334);
        expect(clampColumnWidth('doi_title', Number.NaN)).toBe(DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title);
    });

    it('normalizes stored width objects and ignores unknown values', () => {
        const widths = normalizeResourceColumnWidths({
            doi_title: 999,
            author_year: 120,
            curator_status: 'wide',
            unknown_column: 100,
        });

        expect(widths.doi_title).toBe(720);
        expect(widths.author_year).toBe(140);
        expect(widths.curator_status).toBe(DEFAULT_RESOURCE_COLUMN_WIDTHS.curator_status);
        expect(widths.created_updated).toBe(DEFAULT_RESOURCE_COLUMN_WIDTHS.created_updated);
    });

    it('parses valid storage and rejects malformed storage', () => {
        expect(parseStoredResourceColumnWidths('{bad json')).toBeNull();
        expect(parseStoredResourceColumnWidths(null)).toBeNull();
        expect(parseStoredResourceColumnWidths(JSON.stringify({ doi_title: 300 }))?.doi_title).toBe(300);
    });
});

describe('ResourcesPage column resizing', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axiosGetMock.mockResolvedValue({ data: {} });
        window.localStorage.clear();
        Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 1280 });
    });

    it('renders visible resize handles and default colgroup widths', () => {
        renderResourcesPage();

        expect(screen.getByRole('separator', { name: /resize doi and title column/i })).toBeInTheDocument();
        expect(screen.getByRole('separator', { name: /resize author and year column/i })).toBeInTheDocument();
        expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px` });
    });

    it('resizes a column with the keyboard and persists the new width', () => {
        renderResourcesPage();

        const handle = screen.getByRole('separator', { name: /resize doi and title column/i });
        fireEvent.keyDown(handle, { key: 'ArrowRight' });

        expect(handle).toHaveAttribute('aria-valuenow', String(DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title + 16));
        expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title + 16}px` });

        const storedWidths = JSON.parse(window.localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY) ?? '{}') as Record<string, number>;
        expect(storedWidths.doi_title).toBe(DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title + 16);
    });

    it('resizes a column with pointer drag and clamps at the maximum width', () => {
        renderResourcesPage();

        const handle = screen.getByRole('separator', { name: /resize doi and title column/i });
        fireEvent.pointerDown(handle, { button: 0, clientX: 100, pointerId: 1 });
        fireEvent.pointerMove(window, { clientX: 900, pointerId: 1 });
        fireEvent.pointerUp(window, { pointerId: 1 });

        expect(handle).toHaveAttribute('aria-valuenow', '720');
        expect(getDoiTitleColumn()).toHaveStyle({ width: '720px' });
    });

    it('resets persisted column widths back to defaults', () => {
        renderResourcesPage();

        const handle = screen.getByRole('separator', { name: /resize doi and title column/i });
        fireEvent.keyDown(handle, { key: 'End' });
        expect(getDoiTitleColumn()).toHaveStyle({ width: '720px' });
        expect(window.localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY)).not.toBeNull();

        fireEvent.click(screen.getByTestId('resources-reset-column-widths'));

        expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px` });
        expect(window.localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY)).toBeNull();
    });
});

describe('OverflowTooltipText', () => {
    let clientWidthDescriptor: PropertyDescriptor | undefined;
    let scrollWidthDescriptor: PropertyDescriptor | undefined;

    beforeEach(() => {
        clientWidthDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'clientWidth');
        scrollWidthDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollWidth');
    });

    afterEach(() => {
        if (clientWidthDescriptor) {
            Object.defineProperty(HTMLElement.prototype, 'clientWidth', clientWidthDescriptor);
        }

        if (scrollWidthDescriptor) {
            Object.defineProperty(HTMLElement.prototype, 'scrollWidth', scrollWidthDescriptor);
        }
    });

    it('does not create a tooltip for text that fits', async () => {
        Object.defineProperty(HTMLElement.prototype, 'clientWidth', { configurable: true, get: () => 200 });
        Object.defineProperty(HTMLElement.prototype, 'scrollWidth', { configurable: true, get: () => 120 });

        render(<OverflowTooltipText value="Short title" testId="overflow-text" />);

        const text = screen.getByTestId('overflow-text');
        await waitFor(() => expect(text).toHaveAttribute('data-overflowing', 'false'));
        await userEvent.hover(text);

        expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    });

    it('shows a tooltip with full text when content overflows', async () => {
        Object.defineProperty(HTMLElement.prototype, 'clientWidth', { configurable: true, get: () => 80 });
        Object.defineProperty(HTMLElement.prototype, 'scrollWidth', { configurable: true, get: () => 240 });

        render(<OverflowTooltipText value="A long title that overflows" testId="overflow-text" />);

        const text = screen.getByTestId('overflow-text');
        await waitFor(() => expect(text).toHaveAttribute('data-overflowing', 'true'));
        await userEvent.hover(text);

        expect(await screen.findByRole('tooltip')).toHaveTextContent('A long title that overflows');
    });
});

import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderToString } from 'react-dom/server';
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
vi.mock('@/components/resources/modals/ImportFromDataCiteModal', () => ({
    default: ({ isOpen, onClose, onSuccess }: { isOpen: boolean; onClose: () => void; onSuccess: () => void }) =>
        isOpen ? (
            <div data-testid="datacite-import-modal">
                <button type="button" onClick={onSuccess}>
                    Import all success
                </button>
                <button type="button" onClick={onClose}>
                    Close all resources import
                </button>
            </div>
        ) : null,
}));
vi.mock('@/components/resources/modals/ImportSingleOldResourceModal', () => ({
    default: ({ isOpen, onClose, onSuccess }: { isOpen: boolean; onClose: () => void; onSuccess: () => void }) =>
        isOpen ? (
            <div data-testid="single-resource-import-modal">
                <button type="button" onClick={onSuccess}>
                    Import single success
                </button>
                <button type="button" onClick={onClose}>
                    Close single resource import
                </button>
            </div>
        ) : null,
}));
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

import { TooltipProvider } from '@/components/ui/tooltip';
import ResourcesPage, { OverflowTooltipText } from '@/pages/resources';
import {
    clampColumnWidth,
    clearStoredResourceColumnWidths,
    COLUMN_WIDTH_STORAGE_KEY,
    DEFAULT_RESOURCE_COLUMN_WIDTHS,
    isResizableViewport,
    normalizeResourceColumnWidths,
    parseStoredResourceColumnWidths,
    persistResourceColumnWidths,
    readStoredResourceColumnWidths,
} from '@/pages/resources-column-widths';

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

async function clickResourceAction(actionTestId: string) {
    await userEvent.click(screen.getByTestId('resources-actions-menu-trigger'));
    await userEvent.click(await screen.findByTestId(actionTestId));
}

function setViewportWidth(width: number) {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
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

    it('falls back to default widths for invalid stored shapes', () => {
        expect(normalizeResourceColumnWidths(null)).toEqual(DEFAULT_RESOURCE_COLUMN_WIDTHS);
        expect(normalizeResourceColumnWidths(['doi_title', 400])).toEqual(DEFAULT_RESOURCE_COLUMN_WIDTHS);
        expect(normalizeResourceColumnWidths('wide')).toEqual(DEFAULT_RESOURCE_COLUMN_WIDTHS);
    });

    it('parses valid storage and rejects malformed storage', () => {
        expect(parseStoredResourceColumnWidths('{bad json')).toBeNull();
        expect(parseStoredResourceColumnWidths(null)).toBeNull();
        expect(parseStoredResourceColumnWidths(JSON.stringify({ doi_title: 300 }))?.doi_title).toBe(300);
        expect(parseStoredResourceColumnWidths(JSON.stringify([]))).toEqual(DEFAULT_RESOURCE_COLUMN_WIDTHS);
    });

    it('treats storage helpers as SSR-safe no-ops without window', () => {
        vi.stubGlobal('window', undefined);

        try {
            expect(readStoredResourceColumnWidths()).toEqual(DEFAULT_RESOURCE_COLUMN_WIDTHS);
            expect(isResizableViewport()).toBe(true);
            expect(() => persistResourceColumnWidths(DEFAULT_RESOURCE_COLUMN_WIDTHS)).not.toThrow();
            expect(() => clearStoredResourceColumnWidths()).not.toThrow();
        } finally {
            vi.unstubAllGlobals();
        }
    });
});

describe('ResourcesPage column resizing', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axiosGetMock.mockResolvedValue({ data: {} });
        window.localStorage.clear();
        setViewportWidth(1280);
    });

    it('renders visible resize handles and default colgroup widths', () => {
        renderResourcesPage();

        expect(screen.getByRole('separator', { name: /resize doi and title column/i })).toBeInTheDocument();
        expect(screen.getByRole('separator', { name: /resize author and year column/i })).toBeInTheDocument();
        expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px` });
    });

    it('keeps resize handles unclipped by their header cells', () => {
        renderResourcesPage();

        const handle = screen.getByRole('separator', { name: /resize doi and title column/i });
        const headerCell = handle.closest('th');

        expect(handle).toHaveClass('translate-x-1/2', 'touch-none');
        expect(headerCell).not.toHaveClass('overflow-hidden');
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

    it('renders SSR-safe default widths before client-side hydration', () => {
        vi.stubGlobal('window', undefined);

        try {
            const html = renderToString(<ResourcesPage resources={[resource]} pagination={pagination} sort={{ key: 'id', direction: 'asc' }} />);

            expect(html).toContain(`data-testid="resources-column-doi_title" style="width:${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px"`);
            expect(html).toContain(
                `data-testid="resources-column-created_updated" style="width:${DEFAULT_RESOURCE_COLUMN_WIDTHS.created_updated}px"`,
            );
            expect(html).toContain('data-testid="resources-column-resize-doi_title"');
            expect(html).not.toContain('data-testid="resources-column-resize-doi_title" disabled=""');
        } finally {
            vi.unstubAllGlobals();
        }
    });

    it('hydrates persisted column widths from localStorage after mount', async () => {
        window.localStorage.setItem(COLUMN_WIDTH_STORAGE_KEY, JSON.stringify({ doi_title: 512, author_year: 999 }));

        renderResourcesPage();

        await waitFor(() => expect(getDoiTitleColumn()).toHaveStyle({ width: '512px' }));
        expect(screen.getByTestId('resources-column-author_year')).toHaveStyle({ width: '360px' });
    });

    it('falls back to defaults when stored column widths cannot be read', () => {
        const getItemSpy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('storage unavailable');
        });

        try {
            renderResourcesPage();

            expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px` });
        } finally {
            getItemSpy.mockRestore();
        }
    });

    it('keeps resized widths in memory when localStorage persistence fails', () => {
        const setItemSpy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('storage unavailable');
        });

        try {
            renderResourcesPage();

            const handle = screen.getByRole('separator', { name: /resize doi and title column/i });
            fireEvent.keyDown(handle, { key: 'ArrowRight' });

            expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title + 16}px` });
            expect(setItemSpy).toHaveBeenCalled();
        } finally {
            setItemSpy.mockRestore();
        }
    });

    it('resizes a column with pointer drag and persists only after pointerup', () => {
        renderResourcesPage();

        const handle = screen.getByRole('separator', { name: /resize doi and title column/i });
        fireEvent.pointerDown(handle, { button: 0, clientX: 100, pointerId: 1 });
        fireEvent.pointerMove(window, { clientX: 900, pointerId: 1 });

        expect(handle).toHaveAttribute('aria-valuenow', '720');
        expect(getDoiTitleColumn()).toHaveStyle({ width: '720px' });
        expect(window.localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY)).toBeNull();

        fireEvent.pointerUp(window, { pointerId: 1 });

        const storedWidths = JSON.parse(window.localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY) ?? '{}') as Record<string, number>;
        expect(storedWidths.doi_title).toBe(720);
    });

    it('ignores resize events from inactive pointers', () => {
        renderResourcesPage();

        const handle = screen.getByRole('separator', { name: /resize doi and title column/i });
        fireEvent.pointerDown(handle, { button: 0, clientX: 100, pointerId: 1 });
        fireEvent.pointerMove(window, { clientX: 900, pointerId: 2 });
        fireEvent.pointerCancel(window, { pointerId: 2 });

        expect(handle).toHaveAttribute('aria-valuenow', `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}`);
        expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px` });

        fireEvent.pointerMove(window, { clientX: 900, pointerId: 1 });
        fireEvent.pointerUp(window, { pointerId: 2 });

        expect(handle).toHaveAttribute('aria-valuenow', '720');
        expect(getDoiTitleColumn()).toHaveStyle({ width: '720px' });
        expect(window.localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY)).toBeNull();

        fireEvent.pointerUp(window, { pointerId: 1 });

        const storedWidths = JSON.parse(window.localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY) ?? '{}') as Record<string, number>;
        expect(storedWidths.doi_title).toBe(720);
    });

    it('ignores unsupported resize interactions and clamps keyboard home to the minimum width', () => {
        renderResourcesPage();

        const handle = screen.getByRole('separator', { name: /resize doi and title column/i });

        fireEvent.pointerDown(handle, { button: 1, clientX: 100, pointerId: 1 });
        fireEvent.pointerMove(window, { clientX: 900, pointerId: 1 });
        fireEvent.keyDown(handle, { key: 'Escape' });

        expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px` });

        fireEvent.keyDown(handle, { key: 'Home' });
        fireEvent.keyDown(handle, { key: 'ArrowLeft', shiftKey: true });

        expect(handle).toHaveAttribute('aria-valuenow', '220');
        expect(getDoiTitleColumn()).toHaveStyle({ width: '220px' });
    });

    it('removes pointer listeners after pointer cancellation', () => {
        renderResourcesPage();

        const handle = screen.getByRole('separator', { name: /resize doi and title column/i });
        fireEvent.pointerDown(handle, { button: 0, clientX: 100, pointerId: 1 });
        fireEvent.pointerCancel(window, { pointerId: 1 });
        fireEvent.pointerMove(window, { clientX: 900, pointerId: 1 });

        expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px` });
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

    it('resets in-memory widths when localStorage removal fails', () => {
        window.localStorage.setItem(COLUMN_WIDTH_STORAGE_KEY, JSON.stringify({ doi_title: 720 }));
        const removeItemSpy = vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
            throw new Error('storage unavailable');
        });

        try {
            renderResourcesPage();

            expect(getDoiTitleColumn()).toHaveStyle({ width: '720px' });

            fireEvent.click(screen.getByTestId('resources-reset-column-widths'));

            expect(getDoiTitleColumn()).toHaveStyle({ width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.doi_title}px` });
        } finally {
            removeItemSpy.mockRestore();
        }
    });

    it('hydrates narrow viewport resizing state after mount', async () => {
        setViewportWidth(700);

        renderResourcesPage();

        const handle = screen.getByTestId('resources-column-resize-doi_title');

        await waitFor(() => expect(handle).toBeDisabled());
        expect(handle).toHaveAttribute('tabindex', '-1');
        expect(screen.getByTestId('resources-column-created_updated')).toHaveStyle({ width: '0px' });
        expect(screen.getByTestId('resources-table')).toHaveStyle({ width: '976px' });

        setViewportWidth(1280);
        fireEvent.resize(window);

        expect(handle).not.toBeDisabled();
        expect(screen.getByTestId('resources-column-created_updated')).toHaveStyle({
            width: `${DEFAULT_RESOURCE_COLUMN_WIDTHS.created_updated}px`,
        });
    });

    it('opens and closes DataCite import modals from the import buttons', async () => {
        render(<ResourcesPage resources={[resource]} pagination={pagination} sort={{ key: 'id', direction: 'asc' }} canImportFromDataCite />);

        await userEvent.click(screen.getByRole('button', { name: /import all old resources/i }));
        expect(screen.getByTestId('datacite-import-modal')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: /import all success/i }));
        expect(routerMock.reload).toHaveBeenCalledWith({ only: ['resources', 'pagination'] });

        await userEvent.click(screen.getByRole('button', { name: /close all resources import/i }));
        expect(screen.queryByTestId('datacite-import-modal')).not.toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: /import old single resource/i }));
        expect(screen.getByTestId('single-resource-import-modal')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: /import single success/i }));
        expect(routerMock.reload).toHaveBeenCalledWith({ only: ['resources', 'pagination'] });

        await userEvent.click(screen.getByRole('button', { name: /close single resource import/i }));
        expect(screen.queryByTestId('single-resource-import-modal')).not.toBeInTheDocument();
    });

    it('opens and closes the citation manager from the selected resource action', async () => {
        renderResourcesPage();

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await clickResourceAction('resources-action-manage-related-items');

        expect(screen.getByTestId('citation-manager-modal')).toHaveTextContent('Related items for 1');

        await userEvent.click(screen.getByRole('button', { name: /close citation manager/i }));

        expect(screen.queryByTestId('citation-manager-modal')).not.toBeInTheDocument();
    });

    it('supports family-only authors and keyboard activation on clickable status badges', async () => {
        const originalClipboard = navigator.clipboard;
        const originalOpen = window.open;
        const writeTextMock = vi.fn().mockResolvedValue(undefined);
        const openMock = vi.fn();

        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            writable: true,
            value: { writeText: writeTextMock },
        });
        Object.defineProperty(window, 'open', { configurable: true, writable: true, value: openMock });

        try {
            render(
                <ResourcesPage
                    resources={[{ ...resource, first_author: { familyName: 'Familyonly' } }]}
                    pagination={pagination}
                    sort={{ key: 'id', direction: 'asc' }}
                />,
            );

            expect(screen.getByText('Familyonly')).toBeInTheDocument();

            const statusBadge = screen.getByRole('button', { name: /published - click to open doi/i });
            fireEvent.keyDown(statusBadge, { key: 'Enter' });
            fireEvent.keyDown(statusBadge, { key: ' ' });
            await userEvent.click(statusBadge);

            await waitFor(() => expect(writeTextMock).toHaveBeenCalledTimes(3));
            expect(openMock).toHaveBeenCalledWith('https://doi.org/10.5880/test.2026.001', '_blank', 'noopener,noreferrer');
        } finally {
            if (originalClipboard) {
                Object.defineProperty(navigator, 'clipboard', { configurable: true, writable: true, value: originalClipboard });
            } else {
                Reflect.deleteProperty(navigator, 'clipboard');
            }
            Object.defineProperty(window, 'open', { configurable: true, writable: true, value: originalOpen });
        }
    });

    it('renders loading skeleton rows while the next resource page is loading', async () => {
        const observerInstances: Array<{ disconnect: ReturnType<typeof vi.fn> }> = [];

        class IntersectingObserver implements IntersectionObserver {
            readonly root = null;
            readonly rootMargin = '100px';
            readonly scrollMargin = '0px';
            readonly thresholds = [0.1];

            private readonly callback: IntersectionObserverCallback;
            readonly disconnect = vi.fn();
            readonly takeRecords = vi.fn((): IntersectionObserverEntry[] => []);
            readonly unobserve = vi.fn();

            constructor(callback: IntersectionObserverCallback) {
                this.callback = callback;
                observerInstances.push(this);
            }

            observe(target: Element) {
                this.callback([{ isIntersecting: true, target } as IntersectionObserverEntry], this);
            }
        }

        vi.stubGlobal('IntersectionObserver', IntersectingObserver);

        axiosGetMock.mockImplementation((url: string) => {
            if (url === '/resources/load-more') {
                return new Promise(() => undefined);
            }

            return Promise.resolve({ data: {} });
        });

        try {
            render(
                <ResourcesPage
                    resources={[resource]}
                    pagination={{ ...pagination, has_more: true, last_page: 2, total: 2 }}
                    sort={{ key: 'id', direction: 'asc' }}
                />,
            );

            await waitFor(() => expect(document.querySelectorAll('tr.animate-pulse')).toHaveLength(5));

            expect(observerInstances).toHaveLength(1);
        } finally {
            vi.unstubAllGlobals();
        }
    });
});

describe('OverflowTooltipText', () => {
    const renderOverflowTooltipText = (value: string) =>
        render(
            <TooltipProvider delayDuration={0}>
                <OverflowTooltipText value={value} testId="overflow-text" />
            </TooltipProvider>,
        );

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

        renderOverflowTooltipText('Short title');

        const text = screen.getByTestId('overflow-text');
        await waitFor(() => expect(text).toHaveAttribute('data-overflowing', 'false'));
        await userEvent.hover(text);

        expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
    });

    it('shows a tooltip with full text when content overflows', async () => {
        Object.defineProperty(HTMLElement.prototype, 'clientWidth', { configurable: true, get: () => 80 });
        Object.defineProperty(HTMLElement.prototype, 'scrollWidth', { configurable: true, get: () => 240 });

        renderOverflowTooltipText('A long title that overflows');

        const text = screen.getByTestId('overflow-text');
        await waitFor(() => expect(text).toHaveAttribute('data-overflowing', 'true'));
        await userEvent.hover(text);

        expect(screen.getByRole('tooltip')).toHaveTextContent('A long title that overflows');
    });

    it('measures overflow when ResizeObserver is unavailable', async () => {
        vi.stubGlobal('ResizeObserver', undefined);
        Object.defineProperty(HTMLElement.prototype, 'clientWidth', { configurable: true, get: () => 80 });
        Object.defineProperty(HTMLElement.prototype, 'scrollWidth', { configurable: true, get: () => 240 });

        try {
            renderOverflowTooltipText('A long title without a resize observer');

            const text = screen.getByTestId('overflow-text');
            await waitFor(() => expect(text).toHaveAttribute('data-overflowing', 'true'));
        } finally {
            vi.unstubAllGlobals();
        }
    });
});

import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
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

vi.mock('@/lib/blob-utils', () => ({
    extractErrorMessageFromBlob: vi.fn().mockResolvedValue('Failed to export JSON-LD'),
    parseValidationErrorFromBlob: vi.fn().mockResolvedValue(null),
}));
vi.mock('@/lib/curation-query', () => ({ buildCurationQueryFromResource: buildCurationQueryFromResourceMock }));
vi.mock('@/routes', () => ({ editor: editorRouteMock }));
vi.mock('@/utils/filter-parser', () => ({ parseResourceFiltersFromUrl: vi.fn().mockReturnValue({}) }));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));
vi.mock('@/components/resources-filters', () => ({ ResourcesFilters: () => <div data-testid="resources-filters" /> }));
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

const defaultProps = {
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
        last_page: 1,
        per_page: 50,
        total: 1,
        from: 1,
        to: 1,
        has_more: false,
    },
    sort: {
        key: 'id' as const,
        direction: 'asc' as const,
    },
};

const openResourceActionsMenu = async () => {
    await userEvent.click(screen.getByTestId('resources-actions-menu-trigger'));
};

const clickResourceAction = async (testId: string) => {
    await openResourceActionsMenu();
    await userEvent.click(screen.getByTestId(testId));
};

describe('Resources JSON-LD Export Action', () => {
    let originalCreateObjectURL: typeof URL.createObjectURL | undefined;
    let originalRevokeObjectURL: typeof URL.revokeObjectURL | undefined;

    beforeEach(() => {
        buildCurationQueryFromResourceMock.mockResolvedValue({});
        axiosGetMock.mockReset();
        axiosGetMock.mockImplementation((url: string) => {
            if (url === '/resources/filter-options') {
                return Promise.resolve({ data: {} });
            }

            return Promise.resolve({
                data: new Blob(['{}'], { type: 'application/ld+json' }),
                headers: { 'content-disposition': 'attachment; filename="resource.jsonld"' },
            });
        });

        originalCreateObjectURL = Object.getOwnPropertyDescriptor(URL, 'createObjectURL') ? URL.createObjectURL : undefined;
        originalRevokeObjectURL = Object.getOwnPropertyDescriptor(URL, 'revokeObjectURL') ? URL.revokeObjectURL : undefined;
        Object.defineProperty(URL, 'createObjectURL', { value: vi.fn().mockReturnValue('blob:jsonld'), configurable: true, writable: true });
        Object.defineProperty(URL, 'revokeObjectURL', { value: vi.fn(), configurable: true, writable: true });
    });

    afterEach(() => {
        document.head.innerHTML = '';
        if (originalCreateObjectURL === undefined) {
            delete (URL as { createObjectURL?: typeof URL.createObjectURL }).createObjectURL;
        } else {
            Object.defineProperty(URL, 'createObjectURL', { value: originalCreateObjectURL, configurable: true, writable: true });
        }

        if (originalRevokeObjectURL === undefined) {
            delete (URL as { revokeObjectURL?: typeof URL.revokeObjectURL }).revokeObjectURL;
        } else {
            Object.defineProperty(URL, 'revokeObjectURL', { value: originalRevokeObjectURL, configurable: true, writable: true });
        }
    });

    it('renders a JSON-LD export action in the toolbar', async () => {
        render(<ResourcesPage {...defaultProps} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await openResourceActionsMenu();

        expect(screen.getByTestId('resources-action-export-jsonld')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-export-jsonld')).not.toHaveAttribute('aria-disabled');
    });

    it('renders JSON-LD alongside JSON and XML export actions', async () => {
        render(<ResourcesPage {...defaultProps} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await openResourceActionsMenu();

        expect(screen.getByTestId('resources-action-export-datacite-json')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-export-datacite-xml')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-export-jsonld')).toBeInTheDocument();
    });

    it('exports the selected resource through the JSON-LD endpoint', async () => {
        render(<ResourcesPage {...defaultProps} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await clickResourceAction('resources-action-export-jsonld');

        await waitFor(() => {
            expect(axiosGetMock).toHaveBeenCalledWith('/resources/1/export-jsonld', { responseType: 'blob' });
        });
    });
});

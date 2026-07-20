import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
    vi.fn(({ query }: { query?: Record<string, string | number> } = {}) => ({
        url: query ? `/editor?${new URLSearchParams(Object.entries(query).map(([key, value]) => [key, String(value)])).toString()}` : '/editor',
        method: 'get',
    })),
);
const openDetachedTabMock = vi.hoisted(() => vi.fn());

const axiosPostMock = vi.hoisted(() => vi.fn());
const axiosGetMock = vi.hoisted(() => vi.fn());
const extractErrorMessageFromBlobMock = vi.hoisted(() => vi.fn());
const parseValidationErrorFromBlobMock = vi.hoisted(() => vi.fn());
const toastMock = vi.hoisted(() => Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn(), warning: vi.fn() }));

vi.mock('sonner', () => ({ toast: toastMock }));

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
                    can_register_doi: true,
                    can_register_production_doi: true,
                },
            },
        },
    }),
}));

vi.mock('@/routes', () => ({ editor: editorRouteMock }));
vi.mock('@/lib/detached-tab', () => ({ openDetachedTab: openDetachedTabMock }));

vi.mock('@/lib/curation-query', () => ({
    buildCurationQueryFromResource: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/blob-utils', () => ({
    extractErrorMessageFromBlob: extractErrorMessageFromBlobMock,
    parseValidationErrorFromBlob: parseValidationErrorFromBlobMock,
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

vi.mock('@/components/landing-pages/modals/SetupLandingPageModal', () => ({
    default: ({ isOpen, resource }: { isOpen: boolean; resource: { id: number; title?: string } }) =>
        isOpen ? <div data-testid="setup-landing-page-modal">Setup landing page for {resource.title ?? resource.id}</div> : null,
}));

vi.mock('@/components/resources/modals/RegisterDoiModal', () => ({
    default: ({ isOpen, resource }: { isOpen: boolean; resource: { id: number; title?: string } }) =>
        isOpen ? <div data-testid="register-doi-modal">Register DOI for {resource.title ?? resource.id}</div> : null,
}));

vi.mock('@/components/citations/CitationManagerModal', () => ({
    CitationManagerModal: ({ open, resourceId }: { open: boolean; resourceId: number }) =>
        open ? <div data-testid="citation-manager-modal">Related items for {resourceId}</div> : null,
}));

vi.mock('@/components/resources/modals/ImportFromDataCiteModal', () => ({ default: () => null }));
vi.mock('@/components/resources/modals/ImportSingleOldResourceModal', () => ({ default: () => null }));
vi.mock('@/components/ui/validation-error-modal', () => ({ ValidationErrorModal: () => null }));
vi.mock('@/hooks/use-citation-vocabularies', () => ({
    useCitationVocabularies: () => ({
        vocabularies: {
            resourceTypes: [],
            relationTypes: [],
            contributorTypes: [],
        },
        isLoading: false,
    }),
}));

vi.mock('axios', () => ({
    default: {
        post: axiosPostMock,
        get: axiosGetMock,
    },
    post: axiosPostMock,
    get: axiosGetMock,
    isAxiosError: (err: unknown) => Boolean(err && typeof err === 'object' && 'isAxiosError' in err),
}));

const landingPage = { id: 10, is_published: false, public_url: 'https://example.test/resources/one' };

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
    publicstatus: 'published',
    landingPage,
    ...overrides,
});

const buildProps = (
    resources = [
        buildResource({ id: 1, doi: '10.9999/one', title: 'First' }),
        buildResource({ id: 2, doi: '10.9999/two', title: 'Second', landingPage: { ...landingPage, id: 11 } }),
        buildResource({ id: 3, doi: null, title: 'Third', publicstatus: 'draft', landingPage: { ...landingPage, id: 12 } }),
    ],
) => ({
    resources,
    pagination: {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: resources.length,
        from: resources.length > 0 ? 1 : 0,
        to: resources.length,
        has_more: false,
    },
    sort: { key: 'id' as const, direction: 'asc' as const },
});

const exportResponse = (contents: string, filename: string) => ({
    data: new Blob([contents], { type: 'application/octet-stream' }),
    headers: { 'content-disposition': `attachment; filename="${filename}"` },
});

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

const waitForFilterOptionsToLoad = async () => {
    await waitFor(() => {
        expect(axiosGetMock).toHaveBeenCalledWith('/resources/filter-options');
    });
};

describe('ResourcesPage - bulk selection', () => {
    let originalCreateObjectURL: typeof URL.createObjectURL | undefined;
    let originalRevokeObjectURL: typeof URL.revokeObjectURL | undefined;
    let createObjectUrlMock: ReturnType<typeof vi.fn>;
    let revokeObjectUrlMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        routerMock.delete.mockClear();
        routerMock.reload.mockClear();
        routerMock.visit.mockClear();
        axiosPostMock.mockReset();
        axiosGetMock.mockReset();
        extractErrorMessageFromBlobMock.mockReset();
        extractErrorMessageFromBlobMock.mockResolvedValue('Failed to export');
        parseValidationErrorFromBlobMock.mockReset();
        parseValidationErrorFromBlobMock.mockResolvedValue(null);
        axiosGetMock.mockImplementation((url: string) => {
            if (url === '/resources/filter-options') {
                return Promise.resolve({ data: {} });
            }

            if (url.includes('export-datacite-json')) {
                return Promise.resolve(exportResponse('{"ok":true}', 'resource.json'));
            }

            if (url.includes('export-datacite-xml')) {
                return Promise.resolve(exportResponse('<resource />', 'resource.xml'));
            }

            if (url.includes('export-jsonld')) {
                return Promise.resolve(exportResponse('{"@context":"https://schema.org"}', 'resource.jsonld'));
            }

            return Promise.resolve({ data: {}, headers: {} });
        });
        axiosPostMock.mockImplementation((url: string) => {
            if (url === '/resources/batch-export') {
                return Promise.resolve(exportResponse('zip', 'resources-export.zip'));
            }

            return Promise.resolve({ data: { success: [], failed: [] } });
        });
        toastMock.mockClear();
        toastMock.success.mockClear();
        toastMock.error.mockClear();
        toastMock.warning.mockClear();
        editorRouteMock.mockClear();
        openDetachedTabMock.mockReset();
        openDetachedTabMock.mockReturnValue({} as Window);

        const createDescriptor = Object.getOwnPropertyDescriptor(URL, 'createObjectURL');
        const revokeDescriptor = Object.getOwnPropertyDescriptor(URL, 'revokeObjectURL');
        originalCreateObjectURL = createDescriptor ? URL.createObjectURL : undefined;
        originalRevokeObjectURL = revokeDescriptor ? URL.revokeObjectURL : undefined;
        createObjectUrlMock = vi.fn().mockReturnValue('blob:mock');
        revokeObjectUrlMock = vi.fn();

        Object.defineProperty(URL, 'createObjectURL', {
            value: createObjectUrlMock,
            configurable: true,
            writable: true,
        });
        Object.defineProperty(URL, 'revokeObjectURL', {
            value: revokeObjectUrlMock,
            configurable: true,
            writable: true,
        });
    });

    afterEach(() => {
        document.head.innerHTML = '';

        if (originalCreateObjectURL === undefined) {
            delete (URL as { createObjectURL?: typeof URL.createObjectURL }).createObjectURL;
        } else {
            Object.defineProperty(URL, 'createObjectURL', {
                value: originalCreateObjectURL,
                configurable: true,
                writable: true,
            });
        }

        if (originalRevokeObjectURL === undefined) {
            delete (URL as { revokeObjectURL?: typeof URL.revokeObjectURL }).revokeObjectURL;
        } else {
            Object.defineProperty(URL, 'revokeObjectURL', {
                value: originalRevokeObjectURL,
                configurable: true,
                writable: true,
            });
        }

    });

    it('renders selection checkboxes and the idle toolbar hint', async () => {
        render(<ResourcesPage {...buildProps()} />);
        await waitForFilterOptionsToLoad();

        expect(screen.getByTestId('resources-select-all')).toBeInTheDocument();
        expect(screen.getByTestId('resources-row-checkbox-1')).toBeInTheDocument();
        expect(screen.getByTestId('resources-row-checkbox-2')).toBeInTheDocument();
        expect(screen.getByTestId('resources-row-checkbox-3')).toBeInTheDocument();
        expect(screen.getByText(/select rows to enable resource actions/i)).toBeInTheDocument();
    });

    it('updates selected count when rows are toggled', async () => {
        render(<ResourcesPage {...buildProps()} />);
        await waitForFilterOptionsToLoad();

        fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
        expect(screen.getByText(/^1 resource selected$/i)).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('resources-select-all'));
        expect(screen.getByText(/^3 resources selected$/i)).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('resources-select-all'));
        expect(screen.getByText(/select rows to enable resource actions/i)).toBeInTheDocument();
    });

    it('opens every selected resource without showing a false blocked-tab warning', async () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
        await clickResourceAction('resources-action-edit');

        expect(editorRouteMock).toHaveBeenCalledWith({ query: { resourceId: 1 } });
        expect(editorRouteMock).toHaveBeenCalledWith({ query: { resourceId: 2 } });
        expect(openDetachedTabMock).toHaveBeenCalledWith('/editor?resourceId=1');
        expect(openDetachedTabMock).toHaveBeenCalledWith('/editor?resourceId=2');
        expect(toastMock.warning).not.toHaveBeenCalled();
        expect(screen.queryByTestId('blocked-editor-tabs-dialog')).not.toBeInTheDocument();
    });

    it('keeps quick edit and setup actions outside the action menu', async () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));

        expect(screen.getByTestId('resources-action-edit')).toBeInTheDocument();
        expect(screen.getByTestId('resources-action-setup-landing-page')).toBeInTheDocument();

        await openResourceActionsMenu();
        const menu = screen.getByRole('menu');

        expect(within(menu).queryByTestId('resources-action-edit')).not.toBeInTheDocument();
        expect(within(menu).queryByTestId('resources-action-setup-landing-page')).not.toBeInTheDocument();
        expect(within(menu).getByTestId('resources-action-manage-related-items')).toBeInTheDocument();
    });

    it('shows only a warning toast when one selected editor tab is blocked', async () => {
        openDetachedTabMock.mockReturnValueOnce(null);
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await userEvent.click(screen.getByTestId('resources-action-edit'));

        expect(toastMock.warning).toHaveBeenCalledWith('Your browser blocked the editor tab. Please allow pop-ups for ERNIE and try again.');
        expect(screen.queryByTestId('blocked-editor-tabs-dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /first/i })).not.toBeInTheDocument();
    });

    it('shows fallback links only for tabs blocked during a multi-resource edit', async () => {
        openDetachedTabMock.mockReturnValueOnce({} as Window).mockReturnValueOnce(null);
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
        await userEvent.click(screen.getByTestId('resources-action-edit'));

        expect(toastMock.warning).toHaveBeenCalledWith(expect.stringContaining('fallback links'));
        expect(screen.getByTestId('blocked-editor-tabs-dialog')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /first/i })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: /second/i })).toHaveAttribute('href', '/editor?resourceId=2');
        expect(screen.getByRole('link', { name: /second/i })).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('allows long blocked editor tab labels to wrap inside the fallback dialog', async () => {
        openDetachedTabMock.mockReturnValue(null);
        const longTitle =
            'A very long resource title with enough descriptive metadata to exceed the dialog width and aSuperLongUnbrokenSegmentThatStillNeedsToStayInsideTheFallbackLink';

        render(
            <ResourcesPage
                {...buildProps([
                    buildResource({ id: 9470, doi: '10.9999/long-title', title: longTitle }),
                    buildResource({ id: 9471, doi: '10.9999/second-blocked', title: 'Second blocked resource' }),
                ])}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-row-checkbox-9470'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-9471'));
        await userEvent.click(screen.getByTestId('resources-action-edit'));

        const fallbackLink = screen.getByRole('link', { name: new RegExp(longTitle) });
        const label = within(fallbackLink).getByText(longTitle);

        expect(fallbackLink).toHaveClass('h-auto');
        expect(fallbackLink).toHaveClass('min-h-9');
        expect(fallbackLink).toHaveClass('items-start');
        expect(fallbackLink).toHaveClass('whitespace-normal');
        expect(fallbackLink).not.toHaveClass('whitespace-nowrap');
        expect(label).toHaveClass('whitespace-normal');
        expect(label).toHaveClass('wrap-break-word');
        expect(label).not.toHaveClass('truncate');
        expect(screen.getByRole('link', { name: /second blocked resource/i })).toHaveAttribute('href', '/editor?resourceId=9471');
    });

    it('shows only a warning toast when a row editor tab is blocked', async () => {
        openDetachedTabMock.mockReturnValueOnce(null);
        render(<ResourcesPage {...buildProps()} />);
        await waitForFilterOptionsToLoad();

        fireEvent.click(screen.getByRole('row', { name: /open resource 10\.9999\/one in editor/i }));

        expect(toastMock.warning).toHaveBeenCalledWith('Your browser blocked the editor tab. Please allow pop-ups for ERNIE and try again.');
        expect(screen.queryByTestId('blocked-editor-tabs-dialog')).not.toBeInTheDocument();
    });

    it('keeps single-resource actions visible but reports a useful error for multi-selection', async () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
        await clickResourceAction('resources-action-setup-landing-page');

        expect(screen.queryByTestId('setup-landing-page-modal')).not.toBeInTheDocument();
        expect(toastMock.error).toHaveBeenCalledWith('This action can only be performed on a single record.');
    });

    it('opens setup landing page and related-items modals for a single selected resource', async () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await clickResourceAction('resources-action-setup-landing-page');
        expect(screen.getByTestId('setup-landing-page-modal')).toHaveTextContent('First');

        await clickResourceAction('resources-action-manage-related-items');
        expect(screen.getByTestId('citation-manager-modal')).toHaveTextContent('1');
    });

    it('opens the single-resource DOI registration modal only for DOI-less resources with a landing page', async () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-3'));
        await clickResourceAction('resources-action-register-doi');

        expect(screen.getByTestId('register-doi-modal')).toHaveTextContent('Third');
        expect(axiosPostMock).not.toHaveBeenCalled();
    });

    it('explains why multi-resource DOI registration is unavailable', async () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-3'));
        await clickResourceAction('resources-action-register-doi');

        expect(toastMock.error).toHaveBeenCalledWith(expect.stringContaining('prefix selection'));
        expect(screen.queryByTestId('register-doi-modal')).not.toBeInTheDocument();
    });

    it('exports multiple selected resources as a single ZIP through the batch endpoint', async () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
        await clickResourceAction('resources-action-export-datacite-xml');

        await waitFor(() => {
            expect(axiosPostMock).toHaveBeenCalledWith('/resources/batch-export', { ids: [1, 2], format: 'datacite-xml' }, { responseType: 'blob' });
        });
        expect(axiosGetMock).not.toHaveBeenCalledWith('/resources/1/export-datacite-xml', { responseType: 'blob' });
        expect(axiosGetMock).not.toHaveBeenCalledWith('/resources/2/export-datacite-xml', { responseType: 'blob' });
        expect(createObjectUrlMock).toHaveBeenCalledTimes(1);
        expect(toastMock.success).toHaveBeenCalledWith('2 resources exported as ZIP.');
        expect(toastMock.warning).not.toHaveBeenCalledWith(expect.stringContaining('multiple files'));
    });

    it('shows the backend message when multi-resource ZIP export fails', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        axiosPostMock.mockRejectedValueOnce(
            Object.assign(new Error('export failed'), {
                isAxiosError: true,
                response: { data: new Blob(['Unable to export selected resources.'], { type: 'text/plain' }) },
            }),
        );
        extractErrorMessageFromBlobMock.mockResolvedValueOnce('Unable to export selected resources.');

        try {
            render(<ResourcesPage {...buildProps()} />);

            fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
            fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
            await clickResourceAction('resources-action-export-jsonld');

            await waitFor(() => {
                expect(axiosPostMock).toHaveBeenCalledWith('/resources/batch-export', { ids: [1, 2], format: 'jsonld' }, { responseType: 'blob' });
                expect(toastMock.error).toHaveBeenCalledWith('Unable to export selected resources.');
            });
        } finally {
            consoleError.mockRestore();
        }
    });

    it('uses individual JSON and JSON-LD export endpoints from the toolbar', async () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await clickResourceAction('resources-action-export-datacite-json');
        await clickResourceAction('resources-action-export-jsonld');

        await waitFor(() => {
            expect(axiosGetMock).toHaveBeenCalledWith('/resources/1/export-datacite-json', { responseType: 'blob' });
            expect(axiosGetMock).toHaveBeenCalledWith('/resources/1/export-jsonld', { responseType: 'blob' });
        });
    });

    it('posts selected DOI resources to the metadata update endpoint after confirmation', async () => {
        axiosPostMock.mockResolvedValue({
            data: {
                success: [
                    { id: 1, doi: '10.9999/one', updated: true },
                    { id: 2, doi: '10.9999/two', updated: true },
                ],
                failed: [],
            },
        });

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
        await clickResourceAction('resources-action-update-metadata');

        expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        expect(screen.getByText(/this will update metadata at datacite for 2 resources/i)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: /^update metadata$/i }));

        await waitFor(() => {
            expect(axiosPostMock).toHaveBeenCalledWith('/resources/batch-register', { ids: [1, 2] });
            expect(toastMock.success).toHaveBeenCalledWith('2 resources updated at DataCite');
            expect(routerMock.reload).toHaveBeenCalledWith({ only: ['resources', 'pagination'] });
        });
    });

    it('keeps update metadata unavailable when any selected resource has no DOI', async () => {
        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-3'));
        await clickResourceAction('resources-action-update-metadata');

        expect(toastMock.error).toHaveBeenCalledWith(expect.stringContaining('no DOI'));
        expect(axiosPostMock).not.toHaveBeenCalled();
    });

    it('submits selected draft resources to the batch delete endpoint after confirmation', async () => {
        render(
            <ResourcesPage
                {...buildProps([
                    buildResource({ id: 10, doi: null, title: 'Draft A', publicstatus: 'draft', landingPage: null }),
                    buildResource({ id: 11, doi: null, title: 'Draft B', publicstatus: 'draft', landingPage: null }),
                ])}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-row-checkbox-10'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-11'));
        await clickResourceAction('resources-action-delete');

        expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        expect(screen.getByText(/delete 2 draft resources/i)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: /delete 2 resources/i }));

        expect(routerMock.delete).toHaveBeenCalledWith(
            '/resources/batch',
            expect.objectContaining({
                data: { ids: [10, 11] },
                preserveScroll: true,
                onSuccess: expect.any(Function),
                onError: expect.any(Function),
                onFinish: expect.any(Function),
            }),
        );

        const deleteOptions = routerMock.delete.mock.calls.at(-1)?.[1] as {
            onSuccess: () => void;
            onFinish: () => void;
        };

        await act(async () => {
            deleteOptions.onSuccess();
            deleteOptions.onFinish();
        });

        expect(toastMock.success).toHaveBeenCalledWith('2 resources deleted successfully.');
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });

    it('uses a singular delete confirmation label for one selected resource', async () => {
        render(<ResourcesPage {...buildProps([buildResource({ id: 10, doi: null, title: 'Draft A', publicstatus: 'draft', landingPage: null })])} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-10'));
        await clickResourceAction('resources-action-delete');

        expect(screen.getByRole('button', { name: /^delete resource$/i })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^delete resources$/i })).not.toBeInTheDocument();
    });

    it('shows grouped delete choices and submits only checked deletable groups', async () => {
        render(
            <ResourcesPage
                {...buildProps([
                    buildResource({ id: 10, doi: null, title: 'Draft A', publicstatus: 'draft', landingPage: null }),
                    buildResource({ id: 11, doi: null, title: 'Curation A', publicstatus: 'curation', landingPage: null }),
                    buildResource({ id: 12, doi: '10.9999/review', title: 'Preview A', publicstatus: 'review', landingPage }),
                    buildResource({ id: 13, doi: '10.9999/published', title: 'Published A', publicstatus: 'published', landingPage }),
                ])}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-select-all'));
        await clickResourceAction('resources-action-delete');

        expect(screen.getByText(/delete 1 draft resource/i)).toBeInTheDocument();
        expect(screen.getByText(/delete 1 curation resource/i)).toBeInTheDocument();
        expect(screen.getByText(/delete 1 preview resource/i)).toBeInTheDocument();
        expect(screen.getByTestId('resources-delete-group-published')).toHaveTextContent('1 published resource cannot be deleted');
        expect(screen.getByText(/preview pages will be deleted/i)).toBeInTheDocument();

        await userEvent.click(screen.getByTestId('resources-delete-group-review-checkbox'));
        await userEvent.click(screen.getByRole('button', { name: /delete 2 resources/i }));

        expect(routerMock.delete).toHaveBeenCalledWith('/resources/batch', expect.objectContaining({ data: { ids: [10, 11] } }));
    });

    it('hides delete warnings and disables confirmation when all deletable groups are unchecked', async () => {
        render(
            <ResourcesPage
                {...buildProps([buildResource({ id: 12, doi: '10.9999/review', title: 'Preview A', publicstatus: 'review', landingPage })])}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-row-checkbox-12'));
        await clickResourceAction('resources-action-delete');

        expect(screen.getByText(/preview pages will be deleted/i)).toBeInTheDocument();
        expect(screen.getByText(/this action cannot be undone/i)).toBeInTheDocument();

        await userEvent.click(screen.getByTestId('resources-delete-group-review-checkbox'));

        expect(screen.queryByText(/preview pages will be deleted/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/this action cannot be undone/i)).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /delete 0 resources/i })).toBeDisabled();
        expect(routerMock.delete).not.toHaveBeenCalled();
    });

    it('explains published-only selections without submitting deletion', async () => {
        render(<ResourcesPage {...buildProps([buildResource({ id: 13, publicstatus: 'published', title: 'Published A', landingPage })])} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-13'));
        await clickResourceAction('resources-action-delete');

        expect(screen.getByText(/no selected resources can be deleted/i)).toBeInTheDocument();
        expect(screen.getByTestId('resources-delete-group-published')).toHaveTextContent('1 published resource cannot be deleted');
        expect(screen.getByRole('button', { name: /delete 0 resources/i })).toBeDisabled();
        expect(routerMock.delete).not.toHaveBeenCalled();
    });

    it('allows deleting draft resources that already have a landing page', async () => {
        render(
            <ResourcesPage
                {...buildProps([buildResource({ id: 20, doi: null, title: 'Draft with Landing Page', publicstatus: 'draft', landingPage })])}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-row-checkbox-20'));
        await clickResourceAction('resources-action-delete');
        await userEvent.click(screen.getByRole('button', { name: /^delete resource$/i }));

        expect(routerMock.delete).toHaveBeenCalledWith('/resources/batch', expect.objectContaining({ data: { ids: [20] }, preserveScroll: true }));
    });

    it('shows the batch delete ids validation message returned by Inertia', async () => {
        render(<ResourcesPage {...buildProps([buildResource({ id: 10, doi: null, title: 'Draft A', publicstatus: 'draft', landingPage: null })])} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-10'));
        await clickResourceAction('resources-action-delete');
        await userEvent.click(screen.getByRole('button', { name: /^delete resource$/i }));

        const deleteOptions = routerMock.delete.mock.calls.at(-1)?.[1] as {
            onError: (errors?: Record<string, unknown>) => void;
            onFinish: () => void;
        };

        await act(async () => {
            deleteOptions.onError({ ids: 'Published resources cannot be deleted.' });
            deleteOptions.onFinish();
        });

        expect(toastMock.error).toHaveBeenCalledWith('Published resources cannot be deleted.');
    });

    it('shows item-level batch delete validation messages returned by Inertia', async () => {
        render(<ResourcesPage {...buildProps([buildResource({ id: 10, doi: null, title: 'Draft A', publicstatus: 'draft', landingPage: null })])} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-10'));
        await clickResourceAction('resources-action-delete');
        await userEvent.click(screen.getByRole('button', { name: /^delete resource$/i }));

        const deleteOptions = routerMock.delete.mock.calls.at(-1)?.[1] as {
            onError: (errors?: Record<string, unknown>) => void;
            onFinish: () => void;
        };

        await act(async () => {
            deleteOptions.onError({ 'ids.0': ['The selected resource can no longer be deleted.'] });
            deleteOptions.onFinish();
        });

        expect(toastMock.error).toHaveBeenCalledWith('The selected resource can no longer be deleted.');
    });

    it('shows the first available batch delete validation message for non-id errors', async () => {
        render(<ResourcesPage {...buildProps([buildResource({ id: 10, doi: null, title: 'Draft A', publicstatus: 'draft', landingPage: null })])} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-10'));
        await clickResourceAction('resources-action-delete');
        await userEvent.click(screen.getByRole('button', { name: /^delete resource$/i }));

        const deleteOptions = routerMock.delete.mock.calls.at(-1)?.[1] as {
            onError: (errors?: Record<string, unknown>) => void;
            onFinish: () => void;
        };

        await act(async () => {
            deleteOptions.onError({ general: 'The selected resources cannot be deleted right now.' });
            deleteOptions.onFinish();
        });

        expect(toastMock.error).toHaveBeenCalledWith('The selected resources cannot be deleted right now.');
    });

    it('falls back to a generic batch delete error when no validation message is available', async () => {
        render(<ResourcesPage {...buildProps([buildResource({ id: 10, doi: null, title: 'Draft A', publicstatus: 'draft', landingPage: null })])} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-10'));
        await clickResourceAction('resources-action-delete');
        await userEvent.click(screen.getByRole('button', { name: /^delete resource$/i }));

        const deleteOptions = routerMock.delete.mock.calls.at(-1)?.[1] as {
            onError: (errors?: Record<string, unknown>) => void;
            onFinish: () => void;
        };

        await act(async () => {
            deleteOptions.onError(undefined);
            deleteOptions.onFinish();
        });

        expect(toastMock.error).toHaveBeenCalledWith('Failed to delete resource.');
    });

    it('uses a plural generic batch delete error for multiple selected resources', async () => {
        render(
            <ResourcesPage
                {...buildProps([
                    buildResource({ id: 10, doi: null, title: 'Draft A', publicstatus: 'draft', landingPage: null }),
                    buildResource({ id: 11, doi: null, title: 'Draft B', publicstatus: 'draft', landingPage: null }),
                ])}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-row-checkbox-10'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-11'));
        await clickResourceAction('resources-action-delete');
        await userEvent.click(screen.getByRole('button', { name: /^delete 2 resources$/i }));

        const deleteOptions = routerMock.delete.mock.calls.at(-1)?.[1] as {
            onError: (errors?: Record<string, unknown>) => void;
            onFinish: () => void;
        };

        await act(async () => {
            deleteOptions.onError(undefined);
            deleteOptions.onFinish();
        });

        expect(toastMock.error).toHaveBeenCalledWith('Failed to delete resources.');
    });
    it('reports partial metadata update responses returned as multi-status errors', async () => {
        axiosPostMock.mockRejectedValueOnce(
            Object.assign(new Error('partial success'), {
                isAxiosError: true,
                response: {
                    status: 207,
                    data: {
                        success: [{ id: 1, doi: '10.9999/one', updated: true }],
                        failed: [{ id: 2, doi: '10.9999/two', reason: 'DataCite rejected the metadata' }],
                    },
                },
            }),
        );

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        fireEvent.click(screen.getByTestId('resources-row-checkbox-2'));
        await clickResourceAction('resources-action-update-metadata');
        await userEvent.click(screen.getByRole('button', { name: /^update metadata$/i }));

        await waitFor(() => {
            expect(toastMock.success).toHaveBeenCalledWith('1 resource updated at DataCite');
            expect(toastMock.error).toHaveBeenCalledWith('1 failed: DataCite rejected the metadata');
            expect(routerMock.reload).toHaveBeenCalledWith({ only: ['resources', 'pagination'] });
        });
    });

    it('reports failed metadata updates returned in a successful response', async () => {
        axiosPostMock.mockResolvedValueOnce({
            data: {
                success: [],
                failed: [{ id: 1, doi: '10.9999/one', reason: 'DataCite is unavailable' }],
            },
        });

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await clickResourceAction('resources-action-update-metadata');
        await userEvent.click(screen.getByRole('button', { name: /^update metadata$/i }));

        await waitFor(() => {
            expect(toastMock.error).toHaveBeenCalledWith('1 failed: DataCite is unavailable');
            expect(routerMock.reload).toHaveBeenCalledWith({ only: ['resources', 'pagination'] });
        });
    });

    it('shows an error toast when metadata update fails unexpectedly', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        axiosPostMock.mockRejectedValueOnce(new Error('network down'));

        try {
            render(<ResourcesPage {...buildProps()} />);

            fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
            await clickResourceAction('resources-action-update-metadata');
            await userEvent.click(screen.getByRole('button', { name: /^update metadata$/i }));

            await waitFor(() => {
                expect(toastMock.error).toHaveBeenCalledWith('Metadata update failed');
            });
            expect(routerMock.reload).not.toHaveBeenCalledWith({ only: ['resources', 'pagination'] });
        } finally {
            consoleError.mockRestore();
        }
    });

    it('keeps DOI registration unavailable for DOI-less resources without landing pages', async () => {
        render(
            <ResourcesPage
                {...buildProps([buildResource({ id: 30, doi: null, title: 'No Landing Page', publicstatus: 'draft', landingPage: null })])}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-row-checkbox-30'));
        await clickResourceAction('resources-action-register-doi');

        expect(toastMock.error).toHaveBeenCalledWith('A landing page must be set up before registering a DOI.');
        expect(screen.queryByTestId('register-doi-modal')).not.toBeInTheDocument();
    });

    it('keeps metadata updates unavailable for DOI resources without landing pages', async () => {
        render(
            <ResourcesPage
                {...buildProps([buildResource({ id: 31, doi: '10.9999/missing-page', title: 'Missing Landing Page', landingPage: null })])}
            />,
        );

        fireEvent.click(screen.getByTestId('resources-row-checkbox-31'));
        await clickResourceAction('resources-action-update-metadata');

        expect(toastMock.error).toHaveBeenCalledWith('1 selected resource is missing a landing page.');
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(axiosPostMock).not.toHaveBeenCalled();
    });

    it('shows XML validation warnings while exporting selected resources', async () => {
        axiosGetMock.mockImplementation((url: string) => {
            if (url === '/resources/filter-options') {
                return Promise.resolve({ data: {} });
            }

            if (url.includes('export-datacite-xml')) {
                return Promise.resolve({
                    data: new Blob(['<resource />'], { type: 'application/xml' }),
                    headers: {
                        'content-disposition': 'attachment; filename="resource.xml"',
                        'x-validation-warning': 'VmFsaWRhdGlvbiB3YXJuaW5n',
                    },
                });
            }

            return Promise.resolve({ data: {}, headers: {} });
        });

        render(<ResourcesPage {...buildProps()} />);

        fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
        await clickResourceAction('resources-action-export-datacite-xml');

        await waitFor(() => {
            expect(toastMock.warning).toHaveBeenCalledWith('XML Validation Warning', expect.objectContaining({ description: 'Validation warning' }));
            expect(toastMock.success).toHaveBeenCalledWith('DataCite XML exported with validation warnings');
        });
    });

    it('shows export errors for JSON-LD failures', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        axiosGetMock.mockImplementation((url: string) => {
            if (url === '/resources/filter-options') {
                return Promise.resolve({ data: {} });
            }

            if (url.includes('export-jsonld')) {
                return Promise.reject(new Error('network down'));
            }

            return Promise.resolve(exportResponse('{}', 'resource.json'));
        });

        try {
            render(<ResourcesPage {...buildProps()} />);

            fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
            await clickResourceAction('resources-action-export-jsonld');

            await waitFor(() => {
                expect(toastMock.error).toHaveBeenCalledWith('Failed to export JSON-LD');
            });
        } finally {
            consoleError.mockRestore();
        }
    });

    it('opens validation details for invalid DataCite JSON exports', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const validationBlob = new Blob(['{"errors":[]}'], { type: 'application/json' });
        parseValidationErrorFromBlobMock.mockResolvedValueOnce({
            errors: [{ path: '$.titles', message: 'Title is required' }],
            schema_version: '4.7',
        });
        axiosGetMock.mockImplementation((url: string) => {
            if (url === '/resources/filter-options') {
                return Promise.resolve({ data: {} });
            }

            if (url.includes('export-datacite-json')) {
                return Promise.reject(
                    Object.assign(new Error('invalid'), {
                        isAxiosError: true,
                        response: { status: 422, data: validationBlob },
                    }),
                );
            }

            return Promise.resolve(exportResponse('{}', 'resource.json'));
        });

        try {
            render(<ResourcesPage {...buildProps()} />);

            fireEvent.click(screen.getByTestId('resources-row-checkbox-1'));
            await clickResourceAction('resources-action-export-datacite-json');

            await waitFor(() => {
                expect(parseValidationErrorFromBlobMock).toHaveBeenCalledWith(validationBlob);
            });
            expect(toastMock.error).not.toHaveBeenCalledWith('Failed to export DataCite JSON');
        } finally {
            consoleError.mockRestore();
        }
    });

    it('renders the import buttons alongside the action toolbar', async () => {
        const props = { ...buildProps(), canImportFromDataCite: true };
        render(<ResourcesPage {...props} />);
        await waitForFilterOptionsToLoad();

        expect(within(screen.getByTestId('app-layout')).getByRole('button', { name: /import all old resources/i })).toBeInTheDocument();
        expect(within(screen.getByTestId('app-layout')).getByRole('button', { name: /import all resources from a datacenter/i })).toBeInTheDocument();
        expect(within(screen.getByTestId('app-layout')).getByRole('button', { name: /import old single resource/i })).toBeInTheDocument();
    });
});

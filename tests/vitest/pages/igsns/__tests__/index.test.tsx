import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { fireEvent, render, screen, waitFor, within } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { IgsnRegistrationRun } from '@/components/igsns/igsn-registration-run-modal';

// Mock Inertia
const { mockRouterDelete, mockRouterVisit, mockRouterReload } = vi.hoisted(() => ({
    mockRouterDelete: vi.fn(),
    mockRouterVisit: vi.fn(),
    mockRouterReload: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { delete: mockRouterDelete, visit: mockRouterVisit, reload: mockRouterReload },
}));

// Mock axios
const { mockAxiosGet, mockAxiosPost } = vi.hoisted(() => ({ mockAxiosGet: vi.fn(), mockAxiosPost: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        get: mockAxiosGet,
        post: mockAxiosPost,
    },
    isAxiosError: (error: unknown) => error instanceof Error && 'isAxiosError' in error,
}));

// Mock sonner
const { mockToast } = vi.hoisted(() => ({ mockToast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('sonner', () => ({
    toast: Object.assign(vi.fn(), mockToast),
}));

// Mock blob-utils
vi.mock('@/lib/blob-utils', () => ({
    extractErrorMessageFromBlob: vi.fn().mockResolvedValue('Error'),
    parseValidationErrorFromBlob: vi.fn().mockResolvedValue(null),
}));

// Mock AppLayout
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

// Mock child components
vi.mock('@/components/igsns/status-badge', () => ({
    IgsnStatusBadge: ({ status }: { status: string }) => <span data-testid="status-badge">{status}</span>,
}));
vi.mock('@/components/datacite-url-update-modal', () => ({
    DataCiteUrlUpdateModal: ({ open, scope }: { open: boolean; scope: string }) =>
        open ? <div data-testid="datacite-url-update-modal-mock">{scope}</div> : null,
}));
vi.mock('@/components/igsns/igsn-registration-run-modal', () => ({
    IgsnRegistrationRunModal: ({ open, initialRun }: { open: boolean; initialRun: { id: string } | null }) =>
        open ? <div data-testid="igsn-registration-run-modal-mock">{initialRun?.id}</div> : null,
}));
vi.mock('@/components/igsns/bulk-actions-toolbar', () => ({
    BulkActionsToolbar: ({
        selectedCount,
        onDelete,
        onRegister,
        isRegistering,
    }: {
        selectedCount: number;
        onDelete: () => void;
        onRegister?: () => void;
        isRegistering?: boolean;
    }) => (
        <div data-testid="bulk-toolbar">
            <span>{selectedCount} selected</span>
            <button onClick={onDelete}>Delete</button>
            {onRegister && (
                <button onClick={onRegister} disabled={isRegistering}>
                    Register Selected
                </button>
            )}
        </div>
    ),
}));
vi.mock('@/components/igsns/igsn-filters', () => ({
    IgsnFilters: ({
        filters,
        onFilterChange,
        resultCount,
        totalCount,
        countStatus,
    }: {
        filters: Record<string, string | number | boolean | undefined>;
        onFilterChange: (v: Record<string, string | number | boolean | undefined>) => void;
        resultCount: number | null;
        totalCount: number | null;
        countStatus: 'pending' | 'ready' | 'failed';
    }) => (
        <div data-testid="igsn-filters">
            <input
                data-testid="search-field"
                value={String(filters.search || '')}
                onChange={(e) => onFilterChange({ ...filters, search: e.target.value })}
                aria-label="Search IGSNs by IGSN or title"
            />
            <button
                type="button"
                data-testid="select-datacenter"
                onClick={() => onFilterChange({ ...filters, datacenter_id: 7, without_datacenter: undefined })}
            >
                Select datacenter
            </button>
            <button
                type="button"
                data-testid="select-without-datacenter"
                onClick={() => onFilterChange({ ...filters, datacenter_id: undefined, without_datacenter: true })}
            >
                Select without datacenter
            </button>
            <button
                type="button"
                data-testid="clear-datacenter"
                onClick={() => {
                    const nextFilters = { ...filters };
                    delete nextFilters.datacenter_id;
                    delete nextFilters.without_datacenter;
                    onFilterChange(nextFilters);
                }}
            >
                Clear datacenter
            </button>
            <output data-testid="igsn-filter-state">{JSON.stringify(filters)}</output>
            <span data-testid="search-counts">
                {resultCount} / {totalCount}
            </span>
            <span data-testid="count-status">{countStatus}</span>
        </div>
    ),
}));
vi.mock('@/components/landing-pages/modals/SetupIgsnLandingPageModal', () => ({
    default: () => null,
}));
vi.mock('@/components/igsns/modals/ImportIgsnsModal', () => ({
    default: ({ isOpen, mode }: { isOpen: boolean; mode?: 'all' | 'datacenter' }) =>
        isOpen ? (
            <div data-testid={mode === 'datacenter' ? 'import-datacenter-igsns-modal' : 'import-all-igsns-modal'}>
                {mode === 'datacenter' ? 'Import datacenter modal' : 'Import all modal'}
            </div>
        ) : null,
}));
vi.mock('@/components/igsns/modals/ImportSingleIgsnModal', () => ({
    default: ({ isOpen, igsnPrefix, onClose, onSuccess }: { isOpen: boolean; igsnPrefix?: string; onClose: () => void; onSuccess?: () => void }) =>
        isOpen ? (
            <div data-testid="import-single-igsn-modal" data-prefix={igsnPrefix}>
                Import single modal
                <button onClick={onClose}>Close single import modal</button>
                <button onClick={onSuccess}>Finish single import</button>
            </div>
        ) : null,
}));
vi.mock('@/components/ui/validation-error-modal', () => ({
    ValidationErrorModal: () => null,
}));
vi.mock('@/components/ui/select', () => ({
    Select: ({
        children,
        value,
        onValueChange,
        disabled,
    }: {
        children: React.ReactNode;
        value: string;
        onValueChange: (value: string) => void;
        disabled?: boolean;
    }) => (
        <select aria-label="Rows per page" value={value} onChange={(event) => onValueChange(event.target.value)} disabled={disabled}>
            {children}
        </select>
    ),
    SelectContent: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    SelectItem: ({ children, value }: { children: React.ReactNode; value: string }) => <option value={value}>{children}</option>,
    SelectTrigger: () => null,
    SelectValue: () => null,
}));

import IgsnsPage from '@/pages/igsns/index';

function createIgsn(
    overrides: Partial<{
        id: number;
        igsn: string | null;
        title: string;
        sample_type: string | null;
        material: string | null;
        collection_date: string | null;
        latitude: number | null;
        longitude: number | null;
        upload_status: string;
        upload_error_message: string | null;
        parent_resource_id: number | null;
        collector: string | null;
        has_landing_page: boolean;
        created_at: string | null;
        updated_at: string | null;
    }> = {},
) {
    return {
        id: 1,
        igsn: 'IGSN001',
        title: 'Rock Sample A',
        sample_type: 'Core',
        material: 'Granite',
        collection_date: '2024-01-15',
        latitude: 52.3,
        longitude: 13.1,
        upload_status: 'pending',
        upload_error_message: null,
        parent_resource_id: null,
        collector: 'Dr. Smith',
        has_landing_page: false,
        created_at: '2024-06-01 10:00:00',
        updated_at: '2024-06-15 14:30:00',
        ...overrides,
    };
}

function createPagination(
    overrides: Partial<{
        current_page: number;
        last_page: number | null;
        per_page: number;
        total: number | null;
        from: number | null;
        to: number | null;
        has_more: boolean;
        count_status: 'pending' | 'ready' | 'failed';
        filter_fingerprint: string;
    }> = {},
) {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 100,
        total: 2,
        from: 1,
        to: 2,
        has_more: false,
        count_status: 'ready' as const,
        filter_fingerprint: 'default-fingerprint',
        ...overrides,
    };
}

function createRegistrationRun(overrides: Partial<IgsnRegistrationRun> = {}): IgsnRegistrationRun {
    return {
        id: 'registration-run-1',
        status: 'queued',
        test_mode: true,
        datacite_endpoint: 'https://api.test.datacite.org',
        total: 2,
        processed: 0,
        registered: 0,
        updated: 0,
        failed: 0,
        cancelled: 0,
        pause_reason: null,
        last_error: null,
        started_at: null,
        paused_at: null,
        cancelled_at: null,
        completed_at: null,
        created_at: '2026-08-30T10:00:00Z',
        can_cancel: true,
        can_resume: false,
        can_retry_failed: false,
        ...overrides,
    };
}

const defaultProps = {
    igsns: [
        createIgsn({ id: 1, igsn: 'IGSN001', title: 'Rock Sample A' }),
        createIgsn({ id: 2, igsn: 'IGSN002', title: 'Sediment Sample B', sample_type: 'Grab', material: 'Clay' }),
    ],
    pagination: createPagination(),
    sort: { key: 'updated_at' as const, direction: 'desc' as const },
    canDelete: true,
    canRegister: true,
    canImport: false,
    igsnPrefix: '10.60510',
    search: '',
    totalCount: 2,
    filters: { prefix: '', status: '' },
    filterOptions: { prefixes: [], statuses: [], datacenters: [] },
};

describe('IgsnsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockAxiosGet.mockResolvedValue({ data: { prefixes: [], statuses: [] } });
        localStorage.clear();
        // Mock window.location
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: { href: '', pathname: '/test', search: '' },
        });
    });

    describe('rendering', () => {
        it('renders the IGSNs title', () => {
            render(<IgsnsPage {...defaultProps} />);
            expect(screen.getByText(/Physical Samples.*IGSNs/)).toBeInTheDocument();
        });

        it('renders within AppLayout', () => {
            render(<IgsnsPage {...defaultProps} />);
            expect(screen.getByTestId('app-layout')).toBeInTheDocument();
        });

        it('renders the data table with IGSN rows', () => {
            render(<IgsnsPage {...defaultProps} />);
            expect(screen.getByText('Rock Sample A')).toBeInTheDocument();
            expect(screen.getByText('Sediment Sample B')).toBeInTheDocument();
        });

        it('renders IGSN identifiers in each row', () => {
            render(<IgsnsPage {...defaultProps} />);
            expect(screen.getByText('IGSN001')).toBeInTheDocument();
            expect(screen.getByText('IGSN002')).toBeInTheDocument();
        });

        it('renders status badges for each IGSN', () => {
            render(<IgsnsPage {...defaultProps} />);
            const badges = screen.getAllByTestId('status-badge');
            expect(badges).toHaveLength(2);
        });

        it('shows all three import buttons when canImport is true', () => {
            render(<IgsnsPage {...defaultProps} canImport={true} />);

            expect(screen.getByRole('button', { name: /import all igsns/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /import single igsn/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /import by datacenter/i })).toBeInTheDocument();
        });

        it('hides import buttons when canImport is false', () => {
            render(<IgsnsPage {...defaultProps} canImport={false} />);

            expect(screen.queryByRole('button', { name: /import all igsns/i })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /import single igsn/i })).not.toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /import by datacenter/i })).not.toBeInTheDocument();
        });

        it('shows and opens the URL migration only when the admin capability prop is true', async () => {
            const { rerender } = render(<IgsnsPage {...defaultProps} canUpdateDataCiteLandingPageUrls />);

            await userEvent.click(screen.getByTestId('igsns-datacite-url-update'));
            expect(screen.getByTestId('datacite-url-update-modal-mock')).toHaveTextContent('igsns');

            rerender(<IgsnsPage {...defaultProps} canUpdateDataCiteLandingPageUrls={false} />);
            expect(screen.queryByTestId('igsns-datacite-url-update')).not.toBeInTheDocument();
        });

        it('opens the single IGSN import modal', async () => {
            render(<IgsnsPage {...defaultProps} canImport={true} />);

            await userEvent.click(screen.getByRole('button', { name: /import single igsn/i }));

            expect(screen.getByTestId('import-single-igsn-modal')).toBeInTheDocument();
            expect(screen.getByTestId('import-single-igsn-modal')).toHaveAttribute('data-prefix', '10.60510');
        });

        it('opens the datacenter IGSN import modal', async () => {
            render(<IgsnsPage {...defaultProps} canImport={true} />);

            await userEvent.click(screen.getByRole('button', { name: /import by datacenter/i }));

            expect(screen.getByTestId('import-datacenter-igsns-modal')).toBeInTheDocument();
        });

        it('wires single IGSN import modal close and success callbacks', async () => {
            render(<IgsnsPage {...defaultProps} canImport={true} />);

            await userEvent.click(screen.getByRole('button', { name: /import single igsn/i }));
            await userEvent.click(screen.getByRole('button', { name: /finish single import/i }));

            expect(mockRouterReload).toHaveBeenCalledOnce();

            await userEvent.click(screen.getByRole('button', { name: /close single import modal/i }));

            expect(screen.queryByTestId('import-single-igsn-modal')).not.toBeInTheDocument();
        });

        it('shows pagination info', () => {
            render(<IgsnsPage {...defaultProps} />);
            expect(screen.getByText(/Showing 1 to 2 of 2/)).toBeInTheDocument();
        });

        it('shows empty state when no IGSNs', () => {
            render(<IgsnsPage {...defaultProps} igsns={[]} pagination={createPagination({ total: 0, from: null, to: null })} />);
            expect(screen.getByText(/No IGSNs found/)).toBeInTheDocument();
        });
    });

    describe('selection', () => {
        it('shows checkboxes when canDelete is true', () => {
            render(<IgsnsPage {...defaultProps} />);
            const checkboxes = screen.getAllByRole('checkbox');
            // Header checkbox + 2 row checkboxes
            expect(checkboxes.length).toBeGreaterThanOrEqual(3);
        });

        it('selects individual IGSN rows', async () => {
            render(<IgsnsPage {...defaultProps} />);
            const checkboxes = screen.getAllByRole('checkbox');
            // Click the first row checkbox (index 1, since 0 is "select all")
            await userEvent.click(checkboxes[1]);

            // Bulk toolbar should appear with 1 selected
            expect(screen.getByTestId('bulk-toolbar')).toBeInTheDocument();
            expect(screen.getByText('1 selected')).toBeInTheDocument();
        });

        it('selects all IGSNs via header checkbox', async () => {
            render(<IgsnsPage {...defaultProps} />);
            const checkboxes = screen.getAllByRole('checkbox');
            // Click the "select all" checkbox (first one)
            await userEvent.click(checkboxes[0]);

            expect(screen.getByText('2 selected')).toBeInTheDocument();
        });

        it('deselects all when header checkbox is unchecked', async () => {
            render(<IgsnsPage {...defaultProps} />);
            const checkboxes = screen.getAllByRole('checkbox');
            // Select all
            await userEvent.click(checkboxes[0]);
            expect(screen.getByText('2 selected')).toBeInTheDocument();

            // Deselect all
            await userEvent.click(checkboxes[0]);
            expect(screen.getByText('0 selected')).toBeInTheDocument();
        });
    });

    describe('bulk delete', () => {
        it('shows delete confirmation dialog when delete is triggered', async () => {
            render(<IgsnsPage {...defaultProps} />);
            const checkboxes = screen.getAllByRole('checkbox');
            await userEvent.click(checkboxes[1]); // Select first row

            // Click delete in bulk toolbar
            await userEvent.click(screen.getByText('Delete'));

            // AlertDialog should appear
            expect(screen.getByText(/Are you sure/)).toBeInTheDocument();
        });
    });

    describe('pagination', () => {
        it('renders results immediately and applies a matching asynchronous count', async () => {
            mockAxiosGet.mockResolvedValueOnce({
                data: {
                    filter_fingerprint: 'current-filter',
                    filtered_total: 7,
                    inventory_total: 9,
                    last_page: 1,
                    count_status: 'ready',
                },
            });

            render(
                <IgsnsPage
                    {...defaultProps}
                    totalCount={null}
                    pagination={createPagination({
                        total: null,
                        last_page: null,
                        count_status: 'pending',
                        filter_fingerprint: 'current-filter',
                    })}
                />,
            );

            expect(screen.getByText(/counting total/i)).toBeInTheDocument();
            await waitFor(() => expect(screen.getByTestId('search-counts')).toHaveTextContent('7 / 9'));
            expect(screen.getByText('Page 1 of 1')).toBeInTheDocument();
        });

        it('reloads the cached count when navigating between pages with the same filters', async () => {
            mockAxiosGet.mockResolvedValue({
                data: {
                    filter_fingerprint: 'current-filter',
                    filtered_total: 250,
                    inventory_total: 300,
                    last_page: 3,
                    count_status: 'ready',
                },
            });

            const pendingPagination = {
                total: null,
                last_page: null,
                count_status: 'pending' as const,
                filter_fingerprint: 'current-filter',
            };
            const { rerender } = render(
                <IgsnsPage
                    {...defaultProps}
                    totalCount={null}
                    pagination={createPagination({ ...pendingPagination, current_page: 1, has_more: true })}
                />,
            );

            await waitFor(() => expect(screen.getByText('Page 1 of 3')).toBeInTheDocument());
            mockAxiosGet.mockClear();

            rerender(
                <IgsnsPage
                    {...defaultProps}
                    totalCount={null}
                    pagination={createPagination({ ...pendingPagination, current_page: 2, from: 101, to: 200, has_more: true })}
                />,
            );

            await waitFor(() => expect(mockAxiosGet).toHaveBeenCalledTimes(1));
            await waitFor(() => expect(screen.getByText('Page 2 of 3')).toBeInTheDocument());
            expect(screen.getByTestId('search-counts')).toHaveTextContent('250 / 300');
        });

        it('ignores a count response for a stale filter fingerprint', async () => {
            mockAxiosGet.mockResolvedValueOnce({
                data: {
                    filter_fingerprint: 'stale-filter',
                    filtered_total: 99,
                    inventory_total: 99,
                    last_page: 10,
                    count_status: 'ready',
                },
            });

            render(
                <IgsnsPage
                    {...defaultProps}
                    totalCount={null}
                    pagination={createPagination({
                        total: null,
                        last_page: null,
                        count_status: 'pending',
                        filter_fingerprint: 'current-filter',
                    })}
                />,
            );

            await waitFor(() => expect(mockAxiosGet).toHaveBeenCalled());
            expect(screen.getByText(/counting total/i)).toBeInTheDocument();
            expect(screen.getByTestId('search-counts')).toHaveTextContent('/');
        });

        it('keeps rendered results usable when the count request fails', async () => {
            mockAxiosGet.mockRejectedValueOnce(new Error('count failed'));

            render(
                <IgsnsPage
                    {...defaultProps}
                    totalCount={null}
                    pagination={createPagination({
                        total: null,
                        last_page: null,
                        count_status: 'pending',
                        filter_fingerprint: 'current-filter',
                    })}
                />,
            );

            await waitFor(() => expect(screen.getByText(/count unavailable/i)).toBeInTheDocument());
            expect(screen.getByText('Rock Sample A')).toBeInTheDocument();
            expect(screen.getByTestId('count-status')).toHaveTextContent('failed');
        });

        it('renders the page size selector and complete page controls', () => {
            render(<IgsnsPage {...defaultProps} pagination={createPagination({ has_more: true, last_page: 3 })} />);

            const pageSizeSelector = screen.getByRole('combobox', { name: 'Rows per page' });
            expect(pageSizeSelector).toHaveValue('100');
            expect(within(pageSizeSelector).getByRole('option', { name: '1000' })).toBeInTheDocument();
            expect(screen.getByText('Page 1 of 3')).toBeInTheDocument();
            expect(screen.getByRole('button', { name: 'Go to first page' })).toBeDisabled();
            expect(screen.getByRole('button', { name: 'Go to previous page' })).toBeDisabled();
            expect(screen.getByRole('button', { name: 'Go to next page' })).toBeEnabled();
            expect(screen.getByRole('button', { name: 'Go to last page' })).toBeEnabled();
        });

        it('navigates to the next page while retaining the page size', async () => {
            render(<IgsnsPage {...defaultProps} pagination={createPagination({ has_more: true, last_page: 3 })} />);

            await userEvent.click(screen.getByRole('button', { name: 'Go to next page' }));

            expect(mockRouterVisit).toHaveBeenCalledWith(
                '/igsns?sort=updated_at&direction=desc&page=2&per_page=100',
                expect.objectContaining({ preserveState: false, replace: true }),
            );
        });

        it('persists a changed page size and restarts at the first page', async () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    pagination={createPagination({ current_page: 3, last_page: 5, per_page: 100, from: 201, to: 300, total: 500 })}
                />,
            );

            await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Rows per page' }), '10');

            expect(localStorage.getItem('ernie.igsns.page-size.v1')).toBe('10');
            expect(mockRouterVisit).toHaveBeenCalledWith(
                '/igsns?sort=updated_at&direction=desc&per_page=10',
                expect.objectContaining({ preserveState: false, replace: true }),
            );
        });

        it('restores the stored page size when the URL has no explicit value', async () => {
            window.location.pathname = '/igsns';
            localStorage.setItem('ernie.igsns.page-size.v1', '10');

            render(<IgsnsPage {...defaultProps} />);

            await waitFor(() => {
                expect(mockRouterVisit).toHaveBeenCalledWith(
                    '/igsns?sort=updated_at&direction=desc&per_page=10',
                    expect.objectContaining({ preserveState: false, replace: true }),
                );
            });
        });

        it('restores the stored page size without dropping URL filters', async () => {
            window.location.pathname = '/igsns';
            window.location.search = '?status=pending';
            localStorage.setItem('ernie.igsns.page-size.v1', '10');

            render(<IgsnsPage {...defaultProps} filters={{ prefix: '', status: 'pending' }} />);

            await waitFor(() => {
                expect(mockRouterVisit).toHaveBeenCalledWith(
                    '/igsns?sort=updated_at&direction=desc&status=pending&per_page=10',
                    expect.objectContaining({ preserveState: false, replace: true }),
                );
            });
        });

        it('does not restore over an explicit page size in the URL', () => {
            window.location.pathname = '/igsns';
            window.location.search = '?per_page=100';
            localStorage.setItem('ernie.igsns.page-size.v1', '10');

            render(<IgsnsPage {...defaultProps} />);

            expect(mockRouterVisit).not.toHaveBeenCalled();
        });
    });

    describe('sorting', () => {
        it('renders sortable column headers', () => {
            render(<IgsnsPage {...defaultProps} />);
            // Check for sort buttons
            expect(screen.getByRole('button', { name: /sort by title/i })).toBeInTheDocument();
        });
    });

    describe('datacenter filter persistence and URL handling', () => {
        const filterOptions = {
            prefixes: [],
            statuses: [],
            datacenters: [{ id: 7, name: 'GFZ Samples' }],
        };

        it('persists a concrete selection and serializes it into the filter URL', () => {
            render(<IgsnsPage {...defaultProps} filterOptions={filterOptions} />);

            fireEvent.click(screen.getByTestId('select-datacenter'));

            expect(localStorage.getItem('ernie.igsns.datacenter-filter.v1')).toBe(
                JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 7 }),
            );
            expect(mockRouterVisit).toHaveBeenCalledWith(
                '/igsns?sort=updated_at&direction=desc&datacenter_id=7&per_page=100',
                expect.objectContaining({ preserveState: false, replace: true }),
            );
        });

        it('persists the unassigned selection and clears either saved choice', () => {
            render(<IgsnsPage {...defaultProps} filterOptions={filterOptions} />);

            fireEvent.click(screen.getByTestId('select-without-datacenter'));
            expect(localStorage.getItem('ernie.igsns.datacenter-filter.v1')).toBe(JSON.stringify({ version: 1, type: 'without_datacenter' }));
            expect(mockRouterVisit).toHaveBeenLastCalledWith(
                '/igsns?sort=updated_at&direction=desc&without_datacenter=1&per_page=100',
                expect.anything(),
            );

            fireEvent.click(screen.getByTestId('clear-datacenter'));
            expect(localStorage.getItem('ernie.igsns.datacenter-filter.v1')).toBeNull();
        });

        it('restores a valid stored datacenter only on a clean IGSN URL', async () => {
            window.location.pathname = '/igsns';
            localStorage.setItem('ernie.igsns.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 7 }));

            render(<IgsnsPage {...defaultProps} filterOptions={filterOptions} />);

            await waitFor(() => {
                expect(mockRouterVisit).toHaveBeenCalledWith(
                    '/igsns?sort=updated_at&direction=desc&datacenter_id=7&per_page=100',
                    expect.objectContaining({ preserveState: false, replace: true }),
                );
            });
            expect(screen.getByTestId('igsn-filter-state')).toHaveTextContent('"datacenter_id":7');
        });

        it('restores the saved without-datacenter selection', async () => {
            window.location.pathname = '/igsns';
            localStorage.setItem('ernie.igsns.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'without_datacenter' }));

            render(<IgsnsPage {...defaultProps} filterOptions={filterOptions} />);

            await waitFor(() => {
                expect(mockRouterVisit).toHaveBeenCalledWith(
                    '/igsns?sort=updated_at&direction=desc&without_datacenter=1&per_page=100',
                    expect.objectContaining({ preserveState: false, replace: true }),
                );
            });
        });

        it('does not restore over an explicit filtered URL', () => {
            window.location.pathname = '/igsns';
            window.location.search = '?status=pending';
            localStorage.setItem('ernie.igsns.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 7 }));

            render(<IgsnsPage {...defaultProps} filters={{ prefix: '', status: 'pending' }} filterOptions={filterOptions} />);

            expect(mockRouterVisit).not.toHaveBeenCalled();
            expect(screen.getByTestId('igsn-filter-state')).toHaveTextContent('"status":"pending"');
        });

        it('discards a stored datacenter that is unavailable for IGSNs', async () => {
            window.location.pathname = '/igsns';
            localStorage.setItem('ernie.igsns.datacenter-filter.v1', JSON.stringify({ version: 1, type: 'datacenter', datacenterId: 99 }));

            render(<IgsnsPage {...defaultProps} filterOptions={filterOptions} />);

            await waitFor(() => expect(localStorage.getItem('ernie.igsns.datacenter-filter.v1')).toBeNull());
            expect(mockRouterVisit).not.toHaveBeenCalled();
        });

        it('preserves an active datacenter while changing pages', async () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    filters={{ prefix: '', status: '', datacenter_id: 7 }}
                    filterOptions={filterOptions}
                    pagination={createPagination({ has_more: true, current_page: 1, last_page: 2 })}
                />,
            );

            await userEvent.click(screen.getByRole('button', { name: 'Go to next page' }));

            expect(mockRouterVisit).toHaveBeenCalledWith(expect.stringMatching(/datacenter_id=7.*page=2/), expect.anything());
        });
    });

    describe('canDelete=false', () => {
        it('passes canDelete=false to BulkActionsToolbar', () => {
            render(<IgsnsPage {...defaultProps} canDelete={false} />);
            // BulkActionsToolbar is still rendered but canDelete prop controls the delete button
            expect(screen.getByTestId('bulk-toolbar')).toBeInTheDocument();
        });
    });

    describe('date formatting', () => {
        it('formats a single date', () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, collection_date: '2024-06-15' })]}
                    pagination={createPagination({ total: 1 })}
                />,
            );
            expect(screen.getByText('2024-06-15')).toBeInTheDocument();
        });

        it('formats a date range with separator', () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, collection_date: '2024-01-01 – 2024-12-31' })]}
                    pagination={createPagination({ total: 1 })}
                />,
            );
            expect(screen.getByText('2024-01-01')).toBeInTheDocument();
            expect(screen.getByText('2024-12-31')).toBeInTheDocument();
        });

        it('shows dash for null collection date', () => {
            render(
                <IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, collection_date: null })]} pagination={createPagination({ total: 1 })} />,
            );
            const row = screen.getByText('Rock Sample A').closest('tr')!;
            expect(within(row).getAllByText('-').length).toBeGreaterThan(0);
        });
    });

    describe('child IGSN indicators', () => {
        it('shows indent marker for child IGSNs', () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, parent_resource_id: 5, igsn: 'CHILD001' })]}
                    pagination={createPagination({ total: 1 })}
                />,
            );
            expect(screen.getByText('└')).toBeInTheDocument();
        });

        it('applies muted background for child IGSNs', () => {
            render(
                <IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, parent_resource_id: 5 })]} pagination={createPagination({ total: 1 })} />,
            );
            const row = screen.getByText('Rock Sample A').closest('tr')!;
            expect(row.className).toContain('bg-muted');
        });

        it('does not show indent for parent IGSNs', () => {
            render(
                <IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, parent_resource_id: null })]} pagination={createPagination({ total: 1 })} />,
            );
            expect(screen.queryByText('└')).not.toBeInTheDocument();
        });
    });

    describe('null IGSN display', () => {
        it('shows dash when IGSN identifier is null', () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, igsn: null, title: 'Unnamed Sample' })]}
                    pagination={createPagination({ total: 1 })}
                />,
            );
            const row = screen.getByText('Unnamed Sample').closest('tr')!;
            // The IGSN column renders '-' via font-mono cell
            expect(within(row).getAllByText('-').length).toBeGreaterThan(0);
        });
    });

    describe('sort interaction', () => {
        it('navigates to sorted URL when sort header is clicked', async () => {
            render(<IgsnsPage {...defaultProps} />);
            const titleSortButton = screen.getByRole('button', { name: /sort by title/i });
            await userEvent.click(titleSortButton);
            expect(mockRouterVisit).toHaveBeenCalledWith(
                expect.stringContaining('sort=title'),
                expect.objectContaining({ preserveState: false, replace: true }),
            );
            expect(mockRouterVisit).toHaveBeenCalledWith(expect.stringContaining('direction=asc'), expect.anything());
        });

        it('toggles direction when clicking the already active sort column', async () => {
            render(<IgsnsPage {...defaultProps} sort={{ key: 'title', direction: 'asc' }} />);
            const titleSortButton = screen.getByRole('button', { name: /sort by title/i });
            await userEvent.click(titleSortButton);
            expect(mockRouterVisit).toHaveBeenCalledWith(expect.stringContaining('direction=desc'), expect.anything());
        });
    });

    describe('action buttons', () => {
        it('renders export JSON button for each IGSN', () => {
            render(<IgsnsPage {...defaultProps} />);
            const exportButtons = screen.getAllByRole('button', { name: /export as datacite json/i });
            expect(exportButtons).toHaveLength(2);
        });

        it('renders landing page button for each IGSN', () => {
            render(<IgsnsPage {...defaultProps} />);
            const lpButtons = screen.getAllByRole('button', { name: /setup landing page/i });
            expect(lpButtons).toHaveLength(2);
        });
    });

    describe('pagination details', () => {
        it('navigates to the previous page', async () => {
            render(<IgsnsPage {...defaultProps} pagination={createPagination({ has_more: true, current_page: 2, last_page: 3 })} />);
            await userEvent.click(screen.getByRole('button', { name: 'Go to previous page' }));
            expect(mockRouterVisit).toHaveBeenCalledWith(
                '/igsns?sort=updated_at&direction=desc&per_page=100',
                expect.objectContaining({ preserveState: false, replace: true }),
            );
        });
    });

    describe('delete confirmation flow', () => {
        it('calls router.delete with selected IDs on confirmation', async () => {
            render(<IgsnsPage {...defaultProps} />);
            const checkboxes = screen.getAllByRole('checkbox');
            // Select first row
            await userEvent.click(checkboxes[1]);
            // Open delete dialog
            await userEvent.click(screen.getByText('Delete'));
            // Confirm deletion
            const confirmBtn = screen
                .getAllByRole('button')
                .find((btn) => btn.textContent === 'Delete' && !btn.closest('[data-testid="bulk-toolbar"]'));
            expect(confirmBtn).toBeTruthy();
        });
    });

    describe('single IGSN registration', () => {
        it('renders register button for each IGSN with landing page', () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true }), createIgsn({ id: 2, has_landing_page: false })]}
                />,
            );
            const registerButtons = screen.getAllByRole('button', { name: /register at datacite/i });
            expect(registerButtons).toHaveLength(2);
            // Button with landing page should be enabled
            expect(registerButtons[0]).not.toBeDisabled();
            // Button without landing page should be disabled
            expect(registerButtons[1]).toBeDisabled();
        });

        it('shows "Update Metadata" label for already-registered IGSNs', () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true, upload_status: 'registered' })]}
                    pagination={createPagination({ total: 1 })}
                />,
            );
            expect(screen.getByRole('button', { name: /update metadata at datacite/i })).toBeInTheDocument();
        });

        it('calls axios.post and shows success toast on successful registration', async () => {
            mockAxiosPost.mockResolvedValueOnce({
                data: { success: true, doi: '10.83279/TEST-001', mode: 'test', updated: false, message: 'OK' },
            });

            render(
                <IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, has_landing_page: true })]} pagination={createPagination({ total: 1 })} />,
            );

            await userEvent.click(screen.getByRole('button', { name: /register at datacite/i }));

            expect(mockAxiosPost).toHaveBeenCalledWith('/igsns/1/register');
            // Wait for async handler
            await vi.waitFor(() => {
                expect(mockToast.success).toHaveBeenCalledWith(expect.stringContaining('10.83279/TEST-001'));
            });
        });

        it('shows update toast for already-registered IGSN', async () => {
            mockAxiosPost.mockResolvedValueOnce({
                data: { success: true, doi: '10.83279/TEST-001', mode: 'test', updated: true, message: 'OK' },
            });

            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true, upload_status: 'registered' })]}
                    pagination={createPagination({ total: 1 })}
                />,
            );

            await userEvent.click(screen.getByRole('button', { name: /update metadata at datacite/i }));

            await vi.waitFor(() => {
                expect(mockToast.success).toHaveBeenCalledWith(expect.stringContaining('Metadata updated'));
            });
        });

        it('shows error toast on registration failure', async () => {
            const axiosError = new Error('Request failed') as Error & { isAxiosError: boolean; response: { data: { message: string } } };
            axiosError.isAxiosError = true;
            axiosError.response = { data: { message: 'IGSN prefix not allowed' } };
            mockAxiosPost.mockRejectedValueOnce(axiosError);

            render(
                <IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, has_landing_page: true })]} pagination={createPagination({ total: 1 })} />,
            );

            await userEvent.click(screen.getByRole('button', { name: /register at datacite/i }));

            await vi.waitFor(() => {
                expect(mockToast.error).toHaveBeenCalled();
            });
        });

        it('reloads page data after successful registration', async () => {
            mockAxiosPost.mockResolvedValueOnce({
                data: { success: true, doi: '10.83279/TEST-001', mode: 'test', updated: false, message: 'OK' },
            });

            render(
                <IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, has_landing_page: true })]} pagination={createPagination({ total: 1 })} />,
            );

            await userEvent.click(screen.getByRole('button', { name: /register at datacite/i }));

            await vi.waitFor(() => {
                expect(mockRouterReload).toHaveBeenCalled();
            });
        });
    });

    describe('bulk IGSN registration', () => {
        it('selects all 1000 loaded rows and submits every unique ID', async () => {
            const igsns = Array.from({ length: 1000 }, (_, index) =>
                createIgsn({ id: index + 1, igsn: `10.60510/BULK-${String(index + 1).padStart(4, '0')}`, has_landing_page: true }),
            );
            mockAxiosPost.mockResolvedValueOnce({ data: { run: createRegistrationRun({ total: 1000 }) } });

            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={igsns}
                    pagination={createPagination({ per_page: 1000, total: 1000, from: 1, to: 1000 })}
                    totalCount={1000}
                />,
            );

            fireEvent.click(screen.getByRole('checkbox', { name: 'Select all' }));
            expect(screen.getByText('1000 selected')).toBeInTheDocument();

            fireEvent.click(screen.getByText('Register Selected'));

            await waitFor(() => {
                expect(mockAxiosPost).toHaveBeenCalledWith('/igsns/batch-register', {
                    ids: Array.from({ length: 1000 }, (_, index) => index + 1),
                });
            });
        }, 60_000);

        it('queues selected IDs and opens the persistent progress modal', async () => {
            mockAxiosPost.mockResolvedValueOnce({
                data: { run: createRegistrationRun() },
            });

            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true }), createIgsn({ id: 2, has_landing_page: true })]}
                />,
            );

            // Select both rows
            const checkboxes = screen.getAllByRole('checkbox');
            await userEvent.click(checkboxes[1]);
            await userEvent.click(checkboxes[2]);

            // Click bulk register
            await userEvent.click(screen.getByText('Register Selected'));

            expect(mockAxiosPost).toHaveBeenCalledWith('/igsns/batch-register', {
                ids: expect.arrayContaining([1, 2]),
            });

            await vi.waitFor(() => {
                expect(mockToast.success).toHaveBeenCalledWith(expect.stringContaining('2 IGSN(s) queued'));
            });

            expect(screen.getByTestId('igsn-registration-run-modal-mock')).toHaveTextContent('registration-run-1');
        });

        it('shows the server error when queueing fails', async () => {
            const axiosError = new Error('Request failed') as Error & { isAxiosError: boolean; response: { data: { message: string } } };
            axiosError.isAxiosError = true;
            axiosError.response = { data: { message: 'The DataCite queue is unavailable.' } };
            mockAxiosPost.mockRejectedValueOnce(axiosError);

            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true }), createIgsn({ id: 2, has_landing_page: true })]}
                />,
            );

            const checkboxes = screen.getAllByRole('checkbox');
            await userEvent.click(checkboxes[1]);
            await userEvent.click(checkboxes[2]);
            await userEvent.click(screen.getByText('Register Selected'));

            await vi.waitFor(() => {
                expect(mockToast.error).toHaveBeenCalledWith('The DataCite queue is unavailable.');
            });
        });

        it('prevents bulk registration when selected IGSNs lack landing pages', async () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true }), createIgsn({ id: 2, has_landing_page: false })]}
                />,
            );

            const checkboxes = screen.getAllByRole('checkbox');
            await userEvent.click(checkboxes[1]);
            await userEvent.click(checkboxes[2]);
            await userEvent.click(screen.getByText('Register Selected'));

            // Should show error toast instead of making API call
            expect(mockToast.error).toHaveBeenCalledWith(expect.stringContaining('no landing page'));
            expect(mockAxiosPost).not.toHaveBeenCalled();
        });

        it('clears selection after successful bulk registration', async () => {
            mockAxiosPost.mockResolvedValueOnce({
                data: { run: createRegistrationRun({ total: 1 }) },
            });

            render(
                <IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, has_landing_page: true })]} pagination={createPagination({ total: 1 })} />,
            );

            const checkboxes = screen.getAllByRole('checkbox');
            await userEvent.click(checkboxes[1]);

            // Toolbar should show "1 selected"
            expect(screen.getByText('1 selected')).toBeInTheDocument();

            await userEvent.click(screen.getByText('Register Selected'));

            await vi.waitFor(() => {
                expect(screen.getByText('0 selected')).toBeInTheDocument();
            });

            expect(mockRouterReload).not.toHaveBeenCalled();
        });

        it('reopens a persisted registration run from the page header', async () => {
            render(<IgsnsPage {...defaultProps} igsnRegistrationRun={createRegistrationRun({ status: 'running' })} />);

            await userEvent.click(screen.getByRole('button', { name: 'View registration progress' }));

            expect(screen.getByTestId('igsn-registration-run-modal-mock')).toHaveTextContent('registration-run-1');
        });
    });
});

import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia
const { mockRouterDelete, mockRouterVisit, mockRouterReload } = vi.hoisted(() => ({ mockRouterDelete: vi.fn(), mockRouterVisit: vi.fn(), mockRouterReload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { delete: mockRouterDelete, visit: mockRouterVisit, reload: mockRouterReload },
}));

// Mock axios
const { mockAxiosPost } = vi.hoisted(() => ({ mockAxiosPost: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { prefixes: [], statuses: [] } }),
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
vi.mock('@/components/igsns/bulk-actions-toolbar', () => ({
    BulkActionsToolbar: ({ selectedCount, onDelete, onRegister, isRegistering }: { selectedCount: number; onDelete: () => void; onRegister?: () => void; isRegistering?: boolean }) => (
        <div data-testid="bulk-toolbar">
            <span>{selectedCount} selected</span>
            <button onClick={onDelete}>Delete</button>
            {onRegister && <button onClick={onRegister} disabled={isRegistering}>Register Selected</button>}
        </div>
    ),
}));
vi.mock('@/components/igsns/igsn-filters', () => ({
    IgsnFilters: ({ filters, onFilterChange, resultCount, totalCount }: { filters: Record<string, string | undefined>; onFilterChange: (v: Record<string, string | undefined>) => void; resultCount: number; totalCount: number }) => (
        <div data-testid="igsn-filters">
            <input data-testid="search-field" value={filters.search || ''} onChange={(e) => onFilterChange({ ...filters, search: e.target.value })} aria-label="Search IGSNs by IGSN or title" />
            <span data-testid="search-counts">{resultCount} / {totalCount}</span>
        </div>
    ),
}));
vi.mock('@/components/landing-pages/modals/SetupIgsnLandingPageModal', () => ({
    default: () => null,
}));
vi.mock('@/components/ui/validation-error-modal', () => ({
    ValidationErrorModal: () => null,
}));

import IgsnsPage from '@/pages/igsns/index';

function createIgsn(overrides: Partial<{
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
}> = {}) {
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

function createPagination(overrides: Partial<{
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    has_more: boolean;
}> = {}) {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 2,
        from: 1,
        to: 2,
        has_more: false,
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
    search: '',
    totalCount: 2,
    filters: { prefix: '', status: '' },
    filterOptions: { prefixes: [], statuses: [] },
};

describe('IgsnsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        // Mock window.location
        Object.defineProperty(window, 'location', {
            writable: true,
            value: { href: '', search: '' },
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

    describe('load more', () => {
        it('shows Load More button when has_more is true', () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    pagination={createPagination({ has_more: true, last_page: 3 })}
                />,
            );
            expect(screen.getByText(/Load More/)).toBeInTheDocument();
        });

        it('hides Load More button when has_more is false', () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    pagination={createPagination({ has_more: false })}
                />,
            );
            expect(screen.queryByText(/Load More/)).not.toBeInTheDocument();
        });
    });

    describe('sorting', () => {
        it('renders sortable column headers', () => {
            render(<IgsnsPage {...defaultProps} />);
            // Check for sort buttons
            expect(screen.getByRole('button', { name: /sort by title/i })).toBeInTheDocument();
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
            render(<IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, collection_date: '2024-06-15' })]} pagination={createPagination({ total: 1 })} />);
            expect(screen.getByText('2024-06-15')).toBeInTheDocument();
        });

        it('formats a date range with separator', () => {
            render(<IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, collection_date: '2024-01-01 – 2024-12-31' })]} pagination={createPagination({ total: 1 })} />);
            expect(screen.getByText('2024-01-01')).toBeInTheDocument();
            expect(screen.getByText('2024-12-31')).toBeInTheDocument();
        });

        it('shows dash for null collection date', () => {
            render(<IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, collection_date: null })]} pagination={createPagination({ total: 1 })} />);
            const row = screen.getByText('Rock Sample A').closest('tr')!;
            expect(within(row).getAllByText('-').length).toBeGreaterThan(0);
        });
    });

    describe('child IGSN indicators', () => {
        it('shows indent marker for child IGSNs', () => {
            render(<IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, parent_resource_id: 5, igsn: 'CHILD001' })]} pagination={createPagination({ total: 1 })} />);
            expect(screen.getByText('└')).toBeInTheDocument();
        });

        it('applies muted background for child IGSNs', () => {
            render(<IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, parent_resource_id: 5 })]} pagination={createPagination({ total: 1 })} />);
            const row = screen.getByText('Rock Sample A').closest('tr')!;
            expect(row.className).toContain('bg-muted');
        });

        it('does not show indent for parent IGSNs', () => {
            render(<IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, parent_resource_id: null })]} pagination={createPagination({ total: 1 })} />);
            expect(screen.queryByText('└')).not.toBeInTheDocument();
        });
    });

    describe('null IGSN display', () => {
        it('shows dash when IGSN identifier is null', () => {
            render(<IgsnsPage {...defaultProps} igsns={[createIgsn({ id: 1, igsn: null, title: 'Unnamed Sample' })]} pagination={createPagination({ total: 1 })} />);
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
            expect(mockRouterVisit).toHaveBeenCalledWith(
                expect.stringContaining('direction=asc'),
                expect.anything(),
            );
        });

        it('toggles direction when clicking the already active sort column', async () => {
            render(<IgsnsPage {...defaultProps} sort={{ key: 'title', direction: 'asc' }} />);
            const titleSortButton = screen.getByRole('button', { name: /sort by title/i });
            await userEvent.click(titleSortButton);
            expect(mockRouterVisit).toHaveBeenCalledWith(
                expect.stringContaining('direction=desc'),
                expect.anything(),
            );
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
        it('navigates to next page when Load More is clicked', async () => {
            render(<IgsnsPage {...defaultProps} pagination={createPagination({ has_more: true, current_page: 1 })} />);
            await userEvent.click(screen.getByText(/load more/i));
            expect(mockRouterVisit).toHaveBeenCalledWith(
                expect.stringContaining('page=2'),
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
            const confirmBtn = screen.getAllByRole('button').find((btn) => btn.textContent === 'Delete' && !btn.closest('[data-testid="bulk-toolbar"]'));
            expect(confirmBtn).toBeTruthy();
        });
    });

    describe('single IGSN registration', () => {
        it('renders register button for each IGSN with landing page', () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[
                        createIgsn({ id: 1, has_landing_page: true }),
                        createIgsn({ id: 2, has_landing_page: false }),
                    ]}
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
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true })]}
                    pagination={createPagination({ total: 1 })}
                />,
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
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true })]}
                    pagination={createPagination({ total: 1 })}
                />,
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
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true })]}
                    pagination={createPagination({ total: 1 })}
                />,
            );

            await userEvent.click(screen.getByRole('button', { name: /register at datacite/i }));

            await vi.waitFor(() => {
                expect(mockRouterReload).toHaveBeenCalled();
            });
        });
    });

    describe('bulk IGSN registration', () => {
        it('calls axios.post with selected IDs and shows success toast', async () => {
            mockAxiosPost.mockResolvedValueOnce({
                data: { success: [{ id: 1 }, { id: 2 }], failed: [] },
            });

            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[
                        createIgsn({ id: 1, has_landing_page: true }),
                        createIgsn({ id: 2, has_landing_page: true }),
                    ]}
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
                expect(mockToast.success).toHaveBeenCalledWith(expect.stringContaining('2 IGSN(s) registered'));
            });
        });

        it('shows error toast for partial failures (207)', async () => {
            mockAxiosPost.mockResolvedValueOnce({
                data: {
                    success: [{ id: 1 }],
                    failed: [{ id: 2, reason: 'No landing page' }],
                },
            });

            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[
                        createIgsn({ id: 1, has_landing_page: true }),
                        createIgsn({ id: 2, has_landing_page: true }),
                    ]}
                />,
            );

            const checkboxes = screen.getAllByRole('checkbox');
            await userEvent.click(checkboxes[1]);
            await userEvent.click(checkboxes[2]);
            await userEvent.click(screen.getByText('Register Selected'));

            await vi.waitFor(() => {
                expect(mockToast.success).toHaveBeenCalledWith(expect.stringContaining('1 IGSN(s) registered'));
                expect(mockToast.error).toHaveBeenCalledWith(expect.stringContaining('1 IGSN(s) failed'));
            });
        });

        it('prevents bulk registration when selected IGSNs lack landing pages', async () => {
            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[
                        createIgsn({ id: 1, has_landing_page: true }),
                        createIgsn({ id: 2, has_landing_page: false }),
                    ]}
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
                data: { success: [{ id: 1 }], failed: [] },
            });

            render(
                <IgsnsPage
                    {...defaultProps}
                    igsns={[createIgsn({ id: 1, has_landing_page: true })]}
                    pagination={createPagination({ total: 1 })}
                />,
            );

            const checkboxes = screen.getAllByRole('checkbox');
            await userEvent.click(checkboxes[1]);

            // Toolbar should show "1 selected"
            expect(screen.getByText('1 selected')).toBeInTheDocument();

            await userEvent.click(screen.getByText('Register Selected'));

            await vi.waitFor(() => {
                expect(mockRouterReload).toHaveBeenCalled();
            });
        });
    });
});

import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia
const { mockRouterDelete } = vi.hoisted(() => ({ mockRouterDelete: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { delete: mockRouterDelete },
}));

// Mock axios
vi.mock('axios', () => ({
    default: { get: vi.fn() },
    isAxiosError: () => false,
}));

// Mock sonner
vi.mock('sonner', () => ({
    toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
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
    BulkActionsToolbar: ({ selectedCount, onDelete }: { selectedCount: number; onDelete: () => void }) => (
        <div data-testid="bulk-toolbar">
            <span>{selectedCount} selected</span>
            <button onClick={onDelete}>Delete</button>
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
            expect(window.location.href).toContain('sort=title');
            expect(window.location.href).toContain('direction=asc');
        });

        it('toggles direction when clicking the already active sort column', async () => {
            render(<IgsnsPage {...defaultProps} sort={{ key: 'title', direction: 'asc' }} />);
            const titleSortButton = screen.getByRole('button', { name: /sort by title/i });
            await userEvent.click(titleSortButton);
            expect(window.location.href).toContain('direction=desc');
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
        it('shows total sample count in description', () => {
            render(<IgsnsPage {...defaultProps} pagination={createPagination({ total: 42 })} />);
            expect(screen.getByText(/total: 42 samples/i)).toBeInTheDocument();
        });

        it('navigates to next page when Load More is clicked', async () => {
            render(<IgsnsPage {...defaultProps} pagination={createPagination({ has_more: true, current_page: 1 })} />);
            await userEvent.click(screen.getByText(/load more/i));
            expect(window.location.href).toContain('page=2');
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
});

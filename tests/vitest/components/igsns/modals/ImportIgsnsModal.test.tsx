import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, Mock, vi } from 'vitest';

import ImportIgsnsModal from '@/components/igsns/modals/ImportIgsnsModal';

// Mock axios
vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        get: vi.fn(),
    },
    isAxiosError: vi.fn((error) => error?.isAxiosError === true),
}));

// Mock CSRF token
vi.mock('@/lib/csrf-token', () => ({
    buildCsrfHeaders: () => ({ 'X-CSRF-TOKEN': 'test-token' }),
}));

// Mock Inertia router
vi.mock('@inertiajs/react', () => ({
    router: {
        reload: vi.fn(),
    },
}));

// Mock sonner toast
vi.mock('sonner', () => ({
    toast: {
        info: vi.fn(),
        error: vi.fn(),
        success: vi.fn(),
    },
}));

describe('ImportIgsnsModal', () => {
    const mockOnClose = vi.fn();
    const mockOnSuccess = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders nothing when not open', () => {
        const { container } = render(
            <ImportIgsnsModal isOpen={false} onClose={mockOnClose} />
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('renders modal with title when open', () => {
        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        expect(screen.getByText('Import IGSNs from DataCite')).toBeInTheDocument();
    });

    it('shows confirmation content in initial state', () => {
        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        expect(screen.getByText('What will happen?')).toBeInTheDocument();
        expect(screen.getByText(/~38,500 IGSNs/)).toBeInTheDocument();
    });

    it('shows start import button', () => {
        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        expect(screen.getByRole('button', { name: /start import/i })).toBeInTheDocument();
    });

    it('shows cancel button', () => {
        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
    });

    it('calls onClose when cancel button is clicked', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const cancelButton = screen.getByRole('button', { name: /cancel/i });
        await user.click(cancelButton);

        expect(mockOnClose).toHaveBeenCalled();
    });

    it('shows loading state when starting import', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        // Make the POST request hang
        (axios.post as Mock).mockImplementation(() => new Promise(() => {}));

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        expect(screen.getByText(/starting/i)).toBeInTheDocument();
    });

    it('transitions to running state after successful start', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-igsn-import-123', message: 'IGSN import started' },
        });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 38525,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                enriched: 0,
                skipped_dois: [],
                failed_dois: [],
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByText(/import is in progress/i)).toBeInTheDocument();
        });
    });

    it('shows progress bar during import', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-igsn-import-123', message: 'Import started' },
        });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 38525,
                processed: 19000,
                imported: 18000,
                skipped: 500,
                failed: 500,
                enriched: 17000,
                skipped_dois: [],
                failed_dois: [],
                started_at: new Date().toISOString(),
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByRole('progressbar')).toBeInTheDocument();
        });
    });

    it('displays enriched counter during import', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-igsn-import-123', message: 'Import started' },
        });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 100,
                processed: 50,
                imported: 45,
                skipped: 3,
                failed: 2,
                enriched: 40,
                skipped_dois: [],
                failed_dois: [],
                started_at: new Date().toISOString(),
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByText('40')).toBeInTheDocument();
        });
    });

    it('handles API error gracefully', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockRejectedValue({
            isAxiosError: true,
            response: { status: 500, data: { message: 'Server error' } },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        const { toast } = await import('sonner');
        await waitFor(() => {
            expect(toast.error).toHaveBeenCalledWith('Server error');
        });
    });

    it('handles 403 forbidden error', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockRejectedValue({
            isAxiosError: true,
            response: { status: 403, data: {} },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        const { toast } = await import('sonner');
        await waitFor(() => {
            expect(toast.error).toHaveBeenCalledWith('You do not have permission to import IGSNs.');
        });
    });

    it('resets state when modal closes', async () => {
        const { rerender } = render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        // Close modal
        rerender(
            <ImportIgsnsModal isOpen={false} onClose={mockOnClose} />
        );

        // Reopen modal
        rerender(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        // Should show confirmation state again
        expect(screen.getByText('What will happen?')).toBeInTheDocument();
    });

    it('calls onSuccess callback when completed modal is closed', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-complete-123', message: 'Import started' },
        });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'completed',
                total: 10,
                processed: 10,
                imported: 8,
                skipped: 1,
                failed: 1,
                enriched: 7,
                skipped_dois: ['10.60510/SKIP1'],
                failed_dois: [{ doi: '10.60510/FAIL1', error: 'Test error' }],
                started_at: new Date().toISOString(),
                completed_at: new Date().toISOString(),
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} onSuccess={mockOnSuccess} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        // Wait for completed state
        await waitFor(() => {
            expect(screen.getByText(/import completed/i)).toBeInTheDocument();
        });

        // Close the modal via the outline Close button (not the dialog X)
        const closeButtons = screen.getAllByRole('button', { name: /^close$/i });
        const outlineClose = closeButtons.find(btn => btn.dataset.slot === 'button');
        expect(outlineClose).toBeTruthy();
        await user.click(outlineClose!);

        expect(mockOnSuccess).toHaveBeenCalled();
    });

    it('prevents closing modal while import is running', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-running-123', message: 'Import started' },
        });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 100,
                processed: 10,
                imported: 8,
                skipped: 1,
                failed: 1,
                enriched: 7,
                skipped_dois: [],
                failed_dois: [],
                started_at: new Date().toISOString(),
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByText(/import is in progress/i)).toBeInTheDocument();
        });

        // The Cancel Import button should be visible (not the close/cancel confirmation button)
        expect(screen.getByRole('button', { name: /cancel import/i })).toBeInTheDocument();
    });

    it('sends cancel request when cancel import button is clicked', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock)
            .mockResolvedValueOnce({
                data: { import_id: 'test-cancel-123', message: 'Import started' },
            })
            .mockResolvedValueOnce({ data: { status: 'cancelled' } });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 100,
                processed: 10,
                imported: 8,
                skipped: 1,
                failed: 1,
                enriched: 7,
                skipped_dois: [],
                failed_dois: [],
                started_at: new Date().toISOString(),
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /cancel import/i })).toBeInTheDocument();
        });

        const cancelButton = screen.getByRole('button', { name: /cancel import/i });
        await user.click(cancelButton);

        // Should have called POST twice: start + cancel
        expect(axios.post).toHaveBeenCalledTimes(2);
        expect(axios.post).toHaveBeenLastCalledWith(
            '/igsns/import/test-cancel-123/cancel',
            {},
            expect.objectContaining({ headers: expect.any(Object) }),
        );
    });

    it('shows completed state with summary statistics', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-summary-123', message: 'Import started' },
        });

        const now = new Date().toISOString();
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'completed',
                total: 100,
                processed: 100,
                imported: 90,
                skipped: 5,
                failed: 5,
                enriched: 80,
                skipped_dois: ['10.60510/SKIP1'],
                failed_dois: [{ doi: '10.60510/FAIL1', error: 'Test error' }],
                started_at: now,
                completed_at: now,
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByText(/import completed/i)).toBeInTheDocument();
        });

        // Verify statistics are shown
        expect(screen.getByText('90')).toBeInTheDocument();
        expect(screen.getByText('80')).toBeInTheDocument();
    });

    it('shows failed state with error message', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-fail-123', message: 'Import started' },
        });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'failed',
                error: 'Connection to DataCite API failed',
                total: 0,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                enriched: 0,
                skipped_dois: [],
                failed_dois: [],
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByText(/Connection to DataCite API failed/i)).toBeInTheDocument();
        });
    });

    it('shows close button in completed state', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-close-btn-123', message: 'Import started' },
        });

        const now = new Date().toISOString();
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'completed',
                total: 10,
                processed: 10,
                imported: 10,
                skipped: 0,
                failed: 0,
                enriched: 10,
                skipped_dois: [],
                failed_dois: [],
                started_at: now,
                completed_at: now,
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByText(/import completed/i)).toBeInTheDocument();
        });

        // Close button should be visible in completed state (outline variant, not the dialog X)
        const closeButtons = screen.getAllByRole('button', { name: /close/i });
        const outlineClose = closeButtons.find(btn => btn.textContent === 'Close' && btn.dataset.variant === 'outline');
        expect(outlineClose).toBeTruthy();
    });

    it('shows cancelled state with partial import summary', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-cancelled-123', message: 'Import started' },
        });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'cancelled',
                total: 100,
                processed: 30,
                imported: 25,
                skipped: 3,
                failed: 2,
                enriched: 20,
                skipped_dois: [],
                failed_dois: [],
                started_at: new Date().toISOString(),
                completed_at: new Date().toISOString(),
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByText(/import cancelled/i)).toBeInTheDocument();
        });

        // Should show partial import stats
        expect(screen.getByText('25')).toBeInTheDocument();
        expect(screen.getByText('20')).toBeInTheDocument();

        // Should NOT show "Import Failed" messaging
        expect(screen.queryByText(/import failed/i)).not.toBeInTheDocument();
    });

    it('shows cancelled description text', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-cancelled-desc', message: 'Import started' },
        });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'cancelled',
                total: 50,
                processed: 10,
                imported: 8,
                skipped: 1,
                failed: 1,
                enriched: 7,
                skipped_dois: [],
                failed_dois: [],
                started_at: new Date().toISOString(),
                completed_at: new Date().toISOString(),
            },
        });

        render(
            <ImportIgsnsModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            // The alert title contains "Import Cancelled"
            expect(screen.getByText(/import cancelled/i)).toBeInTheDocument();
        });

        // Should show processing progress in alert description
        expect(screen.getByText(/processing 10 of 50/i)).toBeInTheDocument();
    });
});

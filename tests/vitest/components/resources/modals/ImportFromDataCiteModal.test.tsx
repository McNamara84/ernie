import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, Mock,vi } from 'vitest';

import ImportFromDataCiteModal from '@/components/resources/modals/ImportFromDataCiteModal';

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
    },
}));

describe('ImportFromDataCiteModal', () => {
    const mockOnClose = vi.fn();
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
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
            <ImportFromDataCiteModal isOpen={false} onClose={mockOnClose} />
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('renders modal with title when open', () => {
        render(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        expect(screen.getByText('Import from DataCite')).toBeInTheDocument();
    });

    it('shows confirmation content in initial state', () => {
        render(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        expect(screen.getByText('What will happen?')).toBeInTheDocument();
        expect(screen.getByText(/All DOIs registered with your DataCite credentials will be fetched/)).toBeInTheDocument();
        expect(screen.getByText(/DOIs already in ERNIE will be skipped/)).toBeInTheDocument();
    });

    it('shows start import button', () => {
        render(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        expect(screen.getByRole('button', { name: /start import/i })).toBeInTheDocument();
    });

    it('shows cancel button', () => {
        render(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
    });

    it('calls onClose when cancel button is clicked', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
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
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        expect(screen.getByText(/starting/i)).toBeInTheDocument();
    });

    it('transitions to running state after successful start', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-import-123', message: 'Import started' },
        });

        // Mock the status endpoint to return running state
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 10,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                skipped_dois: [],
                failed_dois: [],
            },
        });

        render(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
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
            data: { import_id: 'test-import-123', message: 'Import started' },
        });

        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 10,
                processed: 5,
                imported: 3,
                skipped: 2,
                failed: 0,
                skipped_dois: [],
                failed_dois: [],
                started_at: new Date().toISOString(),
            },
        });

        render(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        await waitFor(() => {
            expect(screen.getByRole('progressbar')).toBeInTheDocument();
        });
    });

    it('handles API error gracefully', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockRejectedValue({
            isAxiosError: true,
            response: { status: 500, data: { message: 'Server error' } },
        });

        render(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        // Check that toast.error was called
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
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        const startButton = screen.getByRole('button', { name: /start import/i });
        await user.click(startButton);

        const { toast } = await import('sonner');
        await waitFor(() => {
            expect(toast.error).toHaveBeenCalledWith('You do not have permission to import from DataCite.');
        });
    });

    it('resets state when modal closes', async () => {
        const { rerender } = render(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        // Start an import
        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'test-import-123', message: 'Import started' },
        });

        // Close modal
        rerender(
            <ImportFromDataCiteModal isOpen={false} onClose={mockOnClose} />
        );

        // Reopen modal
        rerender(
            <ImportFromDataCiteModal isOpen={true} onClose={mockOnClose} />
        );

        // Should show confirmation state again
        expect(screen.getByText('What will happen?')).toBeInTheDocument();
    });
});

import { router } from '@inertiajs/react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, Mock, vi } from 'vitest';

import ImportSingleIgsnModal from '@/components/igsns/modals/ImportSingleIgsnModal';

vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        get: vi.fn(),
    },
    isAxiosError: vi.fn((error) => error?.isAxiosError === true),
}));

vi.mock('@/lib/csrf-token', () => ({
    buildCsrfHeaders: () => ({ 'X-CSRF-TOKEN': 'test-token' }),
}));

const { mockRouterReload } = vi.hoisted(() => ({ mockRouterReload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    router: {
        reload: mockRouterReload,
    },
}));

vi.mock('sonner', () => ({
    toast: {
        info: vi.fn(),
        error: vi.fn(),
    },
}));

describe('ImportSingleIgsnModal', () => {
    const mockOnClose = vi.fn();
    const mockOnSuccess = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the modal title when open', () => {
        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} onSuccess={mockOnSuccess} />);

        expect(screen.getByText('Import single IGSN')).toBeInTheDocument();
    });

    it('shows a client-side validation error for an invalid IGSN', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'not an igsn');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText(/enter a valid igsn handle/i)).toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('normalizes a DOI URL before submitting the IGSN handle', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-igsn-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 1,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                enriched: 0,
                skipped_dois: [],
                failed_dois: [],
                requested_igsn: 'ICDP5052EUYY001',
                discovered_children: [],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'https://doi.org/10.60510/ICDP5052EUYY001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/igsns/import/start-single',
                { igsn: 'ICDP5052EUYY001' },
                { headers: { 'X-CSRF-TOKEN': 'test-token' } },
            );
        });
    });

    it('uses the configured IGSN prefix for client-side DOI validation', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-igsn-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 1,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                enriched: 0,
                skipped_dois: [],
                failed_dois: [],
                requested_igsn: 'CUSTOM001',
                discovered_children: [],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} igsnPrefix="10.12345" onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), '10.12345/CUSTOM001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/igsns/import/start-single',
                { igsn: 'CUSTOM001' },
                { headers: { 'X-CSRF-TOKEN': 'test-token' } },
            );
        });
    });

    it('shows backend validation errors inline', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    errors: {
                        igsn: ['This IGSN could not be found at DataCite.'],
                    },
                },
            },
            message: 'Validation failed',
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDPMISSING001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('This IGSN could not be found at DataCite.')).toBeInTheDocument();
    });

    it('shows an already-imported state when all requested IGSNs are skipped', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-igsn-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'completed',
                total: 1,
                processed: 1,
                imported: 0,
                skipped: 1,
                failed: 0,
                enriched: 0,
                skipped_dois: ['10.60510/icdp5052euyy001'],
                failed_dois: [],
                requested_igsn: 'ICDP5052EUYY001',
                discovered_children: [],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} onSuccess={mockOnSuccess} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDP5052EUYY001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Already imported')).toBeInTheDocument();
        expect(mockOnSuccess).not.toHaveBeenCalled();
    });

    it('shows parent summary and calls onSuccess after imported children', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-igsn-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'completed',
                total: 3,
                processed: 3,
                imported: 3,
                skipped: 0,
                failed: 0,
                enriched: 2,
                skipped_dois: [],
                failed_dois: [],
                requested_igsn: 'ICDPPARENT001',
                discovered_children: ['ICDPCHILD001', 'ICDPCHILD002'],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} onSuccess={mockOnSuccess} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDPPARENT001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Import complete')).toBeInTheDocument();
        expect(screen.getByText('Related IGSNs included')).toBeInTheDocument();
        expect(screen.getByText(/2 related IGSNs included/i)).toBeInTheDocument();
        expect(mockOnSuccess).toHaveBeenCalledOnce();
    });

    it('sends cancel request while running', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock)
            .mockResolvedValueOnce({
                data: { import_id: 'single-igsn-import-123', message: 'Import started' },
            })
            .mockResolvedValueOnce({ data: { status: 'cancelled' } });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 3,
                processed: 1,
                imported: 1,
                skipped: 0,
                failed: 0,
                enriched: 1,
                skipped_dois: [],
                failed_dois: [],
                requested_igsn: 'ICDPPARENT001',
                discovered_children: ['ICDPCHILD001', 'ICDPCHILD002'],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDPPARENT001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /cancel import/i })).toBeInTheDocument();
        });

        expect(screen.getByText('Related IGSNs detected')).toBeInTheDocument();
        expect(screen.getByText(/2 related IGSNs were discovered and added to this import/i)).toBeInTheDocument();
        expect(screen.queryByText(/Parent IGSN detected/i)).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /cancel import/i }));

        expect(axios.post).toHaveBeenLastCalledWith(
            '/igsns/import/single-igsn-import-123/cancel',
            {},
            expect.objectContaining({ headers: expect.any(Object) }),
        );
    });

    it('refreshes the page when starting fails with a CSRF expiration', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockRejectedValue({
            isAxiosError: true,
            response: { status: 419 },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDP5052EUYY001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        await waitFor(() => {
            expect(toast.error).toHaveBeenCalledWith('Session expired', {
                description: 'Reloading page to refresh session...',
            });
        });

        vi.advanceTimersByTime(1500);

        expect(router.reload).toHaveBeenCalledOnce();
    });

    it('shows a permission error when starting is forbidden', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockRejectedValue({
            isAxiosError: true,
            response: { status: 403 },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDP5052EUYY001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('You do not have permission to import IGSNs.')).toBeInTheDocument();
        expect(toast.error).toHaveBeenCalledWith('You do not have permission to import IGSNs.');
    });

    it('shows response message errors when starting fails without field errors', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 500,
                data: { message: 'DataCite is temporarily unavailable.' },
            },
            message: 'Request failed',
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDP5052EUYY001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('DataCite is temporarily unavailable.')).toBeInTheDocument();
    });

    it('recovers from a transient polling error and then completes', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-igsn-import-123', message: 'Import started' },
        });
        (axios.get as Mock)
            .mockRejectedValueOnce(new Error('temporary network issue'))
            .mockResolvedValueOnce({
                data: {
                    status: 'completed',
                    total: 1,
                    processed: 1,
                    imported: 1,
                    skipped: 0,
                    failed: 0,
                    enriched: 0,
                    skipped_dois: [],
                    failed_dois: [],
                    requested_igsn: 'ICDP5052EUYY001',
                    discovered_children: [],
                },
            });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} onSuccess={mockOnSuccess} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDP5052EUYY001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledTimes(1);
        });

        vi.advanceTimersByTime(2000);

        expect(await screen.findByText('Import complete')).toBeInTheDocument();
        expect(mockOnSuccess).toHaveBeenCalledOnce();
    });

    it('shows failed status from polling using failed DOI fallback error text', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-igsn-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'failed',
                total: 1,
                processed: 1,
                imported: 0,
                skipped: 0,
                failed: 1,
                enriched: 0,
                skipped_dois: [],
                failed_dois: [{ doi: '10.60510/icdpfail001', error: 'Transform failed' }],
                requested_igsn: 'ICDPFAIL001',
                discovered_children: [],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDPFAIL001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Import failed')).toBeInTheDocument();
        expect(screen.getByText('Transform failed')).toBeInTheDocument();
    });

    it('shows cancelled status from polling', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-igsn-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'cancelled',
                total: 3,
                processed: 1,
                imported: 1,
                skipped: 0,
                failed: 0,
                enriched: 1,
                skipped_dois: [],
                failed_dois: [],
                requested_igsn: 'ICDPCANCEL001',
                discovered_children: ['ICDPCHILD001'],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} onSuccess={mockOnSuccess} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDPCANCEL001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Import cancelled')).toBeInTheDocument();
        expect(screen.getByText('Import cancelled').closest('[role="alert"]')).toHaveClass('border-yellow-200');
        expect(screen.getByText('Import cancelled').closest('[role="alert"]')).not.toHaveClass('border-green-200');
        expect(screen.getByText(/Import stopped after processing 1 of 3 IGSNs/i)).toBeInTheDocument();
        expect(mockOnSuccess).toHaveBeenCalledOnce();
    });

    it('shows skipped and failed DOI details after expanding result sections', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-igsn-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'completed',
                total: 3,
                processed: 3,
                imported: 1,
                skipped: 1,
                failed: 1,
                enriched: 1,
                skipped_dois: ['10.60510/icdpskipped001'],
                failed_dois: [{ doi: '10.60510/icdpfailed001', error: 'Missing at DataCite' }],
                requested_igsn: 'ICDPMIXED001',
                discovered_children: [],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDPMIXED001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Import complete')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /skipped igsns/i }));
        await user.click(screen.getByRole('button', { name: /failed igsns/i }));

        expect(screen.getByText('10.60510/icdpskipped001')).toBeInTheDocument();
        expect(screen.getByText('10.60510/icdpfailed001')).toBeInTheDocument();
        expect(screen.getByText('Missing at DataCite')).toBeInTheDocument();
    });

    it('shows an error toast when cancelling fails', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock)
            .mockResolvedValueOnce({
                data: { import_id: 'single-igsn-import-123', message: 'Import started' },
            })
            .mockRejectedValueOnce(new Error('cancel failed'));
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 1,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                enriched: 0,
                skipped_dois: [],
                failed_dois: [],
                requested_igsn: 'ICDPCANCELFAIL001',
                discovered_children: [],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDPCANCELFAIL001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        await waitFor(() => {
            expect(screen.getByRole('button', { name: /cancel import/i })).toBeInTheDocument();
        });

        await user.click(screen.getByRole('button', { name: /cancel import/i }));

        expect(toast.error).toHaveBeenCalledWith('Failed to cancel import');
    });

    it('calls onClose from the completed close button', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-igsn-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'completed',
                total: 1,
                processed: 1,
                imported: 1,
                skipped: 0,
                failed: 0,
                enriched: 0,
                skipped_dois: [],
                failed_dois: [],
                requested_igsn: 'ICDPCLOSE001',
                discovered_children: [],
            },
        });

        render(<ImportSingleIgsnModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('IGSN'), 'ICDPCLOSE001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Import complete')).toBeInTheDocument();

        await user.click(screen.getAllByRole('button', { name: /^close$/i })[0]);

        expect(mockOnClose).toHaveBeenCalledOnce();
    });
});

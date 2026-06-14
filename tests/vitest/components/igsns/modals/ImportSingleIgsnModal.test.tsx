import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
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

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: vi.fn(),
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
        expect(screen.getByText(/2 direct child IGSNs included/i)).toBeInTheDocument();
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

        await user.click(screen.getByRole('button', { name: /cancel import/i }));

        expect(axios.post).toHaveBeenLastCalledWith(
            '/igsns/import/single-igsn-import-123/cancel',
            {},
            expect.objectContaining({ headers: expect.any(Object) }),
        );
    });
});

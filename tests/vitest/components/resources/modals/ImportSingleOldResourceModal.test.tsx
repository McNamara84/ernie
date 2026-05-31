import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, Mock, vi } from 'vitest';

import ImportSingleOldResourceModal from '@/components/resources/modals/ImportSingleOldResourceModal';

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

describe('ImportSingleOldResourceModal', () => {
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
        render(<ImportSingleOldResourceModal isOpen={true} onClose={mockOnClose} onSuccess={mockOnSuccess} />);

        expect(screen.getByText('Import old single Resource')).toBeInTheDocument();
    });

    it('shows a client-side validation error for an invalid DOI', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<ImportSingleOldResourceModal isOpen={true} onClose={mockOnClose} onSuccess={mockOnSuccess} />);

        await user.type(screen.getByLabelText('DOI'), 'not-a-doi');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText(/invalid doi format/i)).toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('normalizes a DOI URL before submitting it', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'running',
                total: 1,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                skipped_dois: [],
                failed_dois: [],
            },
        });

        render(<ImportSingleOldResourceModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('DOI'), 'https://doi.org/10.5880/GFZ.OJSJ.2026.001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/datacite/import/start-single',
                { doi: '10.5880/gfz.ojsj.2026.001' },
                { headers: { 'X-CSRF-TOKEN': 'test-token' } },
            );
        });
    });

    it('shows backend DOI validation errors inline', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockRejectedValue({
            isAxiosError: true,
            response: {
                status: 422,
                data: {
                    errors: {
                        doi: ['Only GFZ legacy resources can be imported with this action.'],
                    },
                },
            },
            message: 'Validation failed',
        });

        render(<ImportSingleOldResourceModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('DOI'), '10.5880/gfz.ojsj.2026.001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Only GFZ legacy resources can be imported with this action.')).toBeInTheDocument();
    });

    it('shows an already-imported state when the single DOI is skipped', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'completed',
                total: 1,
                processed: 1,
                imported: 0,
                skipped: 1,
                failed: 0,
                skipped_dois: ['10.5880/gfz.ojsj.2026.001'],
                failed_dois: [],
            },
        });

        render(<ImportSingleOldResourceModal isOpen={true} onClose={mockOnClose} />);

        await user.type(screen.getByLabelText('DOI'), '10.5880/gfz.ojsj.2026.001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Already imported')).toBeInTheDocument();
        expect(screen.getByText(/was not imported again/i)).toBeInTheDocument();
        expect(mockOnSuccess).not.toHaveBeenCalled();
    });

    it('calls onSuccess as soon as a new resource was imported', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        (axios.post as Mock).mockResolvedValue({
            data: { import_id: 'single-import-123', message: 'Import started' },
        });
        (axios.get as Mock).mockResolvedValue({
            data: {
                status: 'completed',
                total: 1,
                processed: 1,
                imported: 1,
                skipped: 0,
                failed: 0,
                skipped_dois: [],
                failed_dois: [],
            },
        });

        render(<ImportSingleOldResourceModal isOpen={true} onClose={mockOnClose} onSuccess={mockOnSuccess} />);

        await user.type(screen.getByLabelText('DOI'), '10.5880/gfz.ojsj.2026.001');
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Import complete')).toBeInTheDocument();
        expect(mockOnSuccess).toHaveBeenCalledOnce();
        expect(mockOnClose).not.toHaveBeenCalled();
    });
});
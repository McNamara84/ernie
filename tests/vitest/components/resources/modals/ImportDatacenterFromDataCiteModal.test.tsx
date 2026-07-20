import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, Mock, vi } from 'vitest';

import ImportFromDataCiteModal from '@/components/resources/modals/ImportFromDataCiteModal';

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

const datacenters = [
    { id: 'ArboDat', name: 'ArboDat 2016', resource_count: 172 },
    { id: 'GFZ', name: 'GFZ Data Services', resource_count: 1200 },
];

describe('ImportFromDataCiteModal datacenter mode', () => {
    const onClose = vi.fn();
    const onSuccess = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('loads datacenters when opened and describes the hybrid selection', async () => {
        (axios.get as Mock).mockResolvedValueOnce({ data: { datacenters } });

        render(<ImportFromDataCiteModal isOpen={true} onClose={onClose} mode="datacenter" />);

        expect(screen.getByText('Import all Resources from a Datacenter')).toBeInTheDocument();
        expect(screen.getByText(/Visible resources are selected through the GFZ Data Services portal/)).toBeInTheDocument();
        expect(screen.getByText(/Matching pending SUMARIO resources are selected through the legacy databases and DOI rules/)).toBeInTheDocument();

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledWith('/datacite/import/datacenters');
        });

        expect(screen.getByRole('combobox', { name: 'Datacenter' })).toBeEnabled();
        expect(screen.getByRole('button', { name: /start import/i })).toBeDisabled();
    });

    it('starts the import with the selected datacenter id', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        (axios.get as Mock).mockResolvedValueOnce({ data: { datacenters } }).mockResolvedValue({
            data: {
                status: 'running',
                total: 172,
                processed: 0,
                imported: 0,
                skipped: 0,
                failed: 0,
                skipped_dois: [],
                failed_dois: [],
            },
        });
        (axios.post as Mock).mockResolvedValueOnce({
            data: { import_id: 'datacenter-import-123', message: 'Datacenter import started successfully' },
        });

        render(<ImportFromDataCiteModal isOpen={true} onClose={onClose} mode="datacenter" />);

        const select = await screen.findByRole('combobox', { name: 'Datacenter' });
        await user.click(select);
        await user.click(await screen.findByRole('option', { name: /ArboDat 2016/ }));

        expect(screen.getByText(/172 visible portal resources; matching pending resources are included/)).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /start import/i }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith(
                '/datacite/import/start-datacenter',
                { datacenter_id: 'ArboDat' },
                { headers: { 'X-CSRF-TOKEN': 'test-token' } },
            );
        });
    });

    it('shows the upstream error and retries loading the datacenter list', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        (axios.get as Mock)
            .mockRejectedValueOnce({
                isAxiosError: true,
                response: { data: { message: 'Portal is temporarily unavailable.' } },
            })
            .mockResolvedValueOnce({ data: { datacenters } });

        render(<ImportFromDataCiteModal isOpen={true} onClose={onClose} mode="datacenter" />);

        expect(await screen.findByText('Portal is temporarily unavailable.')).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: 'Datacenter' })).toBeDisabled();

        await user.click(screen.getByRole('button', { name: /try again/i }));

        await waitFor(() => {
            expect(axios.get).toHaveBeenCalledTimes(2);
            expect(screen.getByRole('combobox', { name: 'Datacenter' })).toBeEnabled();
        });
    });

    it('shows a non-fatal pending-resource warning returned by the job', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        (axios.get as Mock).mockResolvedValueOnce({ data: { datacenters } }).mockResolvedValueOnce({
            data: {
                status: 'completed',
                total: 1,
                processed: 1,
                imported: 1,
                skipped: 0,
                failed: 0,
                skipped_dois: [],
                failed_dois: [],
                warnings: ['Matching SUMARIO pending resources could not be loaded.'],
            },
        });
        (axios.post as Mock).mockResolvedValueOnce({
            data: { import_id: 'datacenter-import-123', message: 'Datacenter import started successfully' },
        });

        render(<ImportFromDataCiteModal isOpen={true} onClose={onClose} onSuccess={onSuccess} mode="datacenter" />);

        await user.click(await screen.findByRole('combobox', { name: 'Datacenter' }));
        await user.click(await screen.findByRole('option', { name: /GFZ Data Services/ }));
        await user.click(screen.getByRole('button', { name: /start import/i }));

        expect(await screen.findByText('Import warning')).toBeInTheDocument();
        expect(screen.getByText('Matching SUMARIO pending resources could not be loaded.')).toBeInTheDocument();
        expect(screen.getByText('Import Complete')).toBeInTheDocument();
        expect(onSuccess).toHaveBeenCalledOnce();
    });
});

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { beforeEach, describe, expect, it, Mock, vi } from 'vitest';

import { ImportDataCiteSyncStatus } from '@/components/imports/ImportDataCiteSyncStatus';

vi.mock('axios', () => ({
    default: { post: vi.fn() },
    isAxiosError: vi.fn(() => false),
}));

vi.mock('@/lib/csrf-token', () => ({
    buildCsrfHeaders: () => ({ 'X-CSRF-TOKEN': 'test-token' }),
}));

vi.mock('sonner', () => ({
    toast: { info: vi.fn(), error: vi.fn() },
}));

describe('ImportDataCiteSyncStatus', () => {
    beforeEach(() => vi.clearAllMocks());

    it('explains that test mode kept the operation local', () => {
        render(<ImportDataCiteSyncStatus progress={{ sync_total: 2, sync_skipped_test_mode: true }} retryUrl="/retry" onRetryStarted={vi.fn()} />);

        expect(screen.getByText('DataCite update skipped')).toBeInTheDocument();
        expect(screen.getByText(/no metadata was written to DataCite/i)).toBeInTheDocument();
    });

    it('shows successful production updates', () => {
        render(
            <ImportDataCiteSyncStatus progress={{ sync_total: 2, sync_succeeded: 2, sync_failed: 0 }} retryUrl="/retry" onRetryStarted={vi.fn()} />,
        );

        expect(screen.getByText('DataCite metadata updated')).toBeInTheDocument();
        expect(screen.getByText(/2 records now point/i)).toBeInTheDocument();
    });

    it('starts a retry for failed updates without discarding local data', async () => {
        const onRetryStarted = vi.fn();
        (axios.post as Mock).mockResolvedValue({ data: { message: 'started' } });

        render(
            <ImportDataCiteSyncStatus
                progress={{
                    sync_total: 2,
                    sync_succeeded: 1,
                    sync_failed: 1,
                    sync_retry_available: true,
                    sync_errors: [{ resource_id: 7, doi: '10.60510/test', error: 'API unavailable' }],
                }}
                retryUrl="/igsns/import/import-id/retry-sync"
                onRetryStarted={onRetryStarted}
            />,
        );

        expect(screen.getByText(/published landing pages were kept/i)).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: /retry failed updates/i }));

        await waitFor(() => {
            expect(axios.post).toHaveBeenCalledWith('/igsns/import/import-id/retry-sync', {}, { headers: { 'X-CSRF-TOKEN': 'test-token' } });
            expect(onRetryStarted).toHaveBeenCalledOnce();
        });
    });
});

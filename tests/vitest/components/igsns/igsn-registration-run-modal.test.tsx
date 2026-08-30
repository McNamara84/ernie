import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { act, render, screen, waitFor } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { type IgsnRegistrationRun, IgsnRegistrationRunModal } from '@/components/igsns/igsn-registration-run-modal';

const { mockGet, mockPost, mockToastSuccess } = vi.hoisted(() => ({
    mockGet: vi.fn(),
    mockPost: vi.fn(),
    mockToastSuccess: vi.fn(),
}));

vi.mock('axios', () => ({
    default: {
        get: mockGet,
        post: mockPost,
    },
    isAxiosError: (error: unknown) => !!error && typeof error === 'object' && 'isAxiosError' in error,
}));

vi.mock('sonner', () => ({
    toast: {
        success: mockToastSuccess,
        error: vi.fn(),
    },
}));

const baseRun: IgsnRegistrationRun = {
    id: '01991a3a-0000-7000-8000-000000000001',
    status: 'running',
    test_mode: true,
    datacite_endpoint: 'https://api.test.datacite.org',
    total: 1000,
    processed: 275,
    registered: 200,
    updated: 70,
    failed: 5,
    cancelled: 0,
    pause_reason: null,
    last_error: null,
    started_at: '2026-08-30T10:00:00+02:00',
    paused_at: null,
    cancelled_at: null,
    completed_at: null,
    created_at: '2026-08-30T09:59:00+02:00',
    can_cancel: true,
    can_resume: false,
    can_retry_failed: false,
};

const emptyIssues = {
    items: [],
    pagination: { current_page: 1, last_page: 1, total: 0 },
};

describe('IgsnRegistrationRunModal', () => {
    beforeEach(() => {
        mockGet.mockReset();
        mockPost.mockReset();
        mockToastSuccess.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('restores a running 1000-item registration and displays its progress and DataCite mode', async () => {
        mockGet.mockResolvedValueOnce({ data: { run: baseRun } });

        render(<IgsnRegistrationRunModal open onOpenChange={vi.fn()} initialRun={baseRun} />);

        expect(await screen.findByText('275 / 1000')).toBeInTheDocument();
        expect(screen.getByText('DataCite Test')).toBeInTheDocument();
        expect(screen.getByText('Registering at DataCite')).toBeInTheDocument();
        expect(screen.getByText('725')).toBeInTheDocument();
        expect(mockGet).toHaveBeenCalledWith(`/igsns/batch-register/${baseRun.id}`);
    });

    it('polls a non-terminal registration while the dialog is open', async () => {
        vi.useFakeTimers();
        mockGet.mockResolvedValue({ data: { run: baseRun } });

        render(<IgsnRegistrationRunModal open onOpenChange={vi.fn()} initialRun={baseRun} />);

        await act(async () => {
            await Promise.resolve();
        });
        expect(mockGet).toHaveBeenCalledTimes(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(3000);
        });
        expect(mockGet).toHaveBeenCalledTimes(2);
    });

    it('loads paginated issues for a paused run and resumes it', async () => {
        const pausedRun: IgsnRegistrationRun = {
            ...baseRun,
            status: 'paused',
            pause_reason: 'DataCite authentication failed.',
            paused_at: '2026-08-30T10:10:00+02:00',
            can_resume: true,
        };
        const resumedRun: IgsnRegistrationRun = {
            ...pausedRun,
            status: 'queued',
            pause_reason: null,
            paused_at: null,
            can_resume: false,
        };
        mockGet
            .mockResolvedValueOnce({ data: { run: pausedRun } })
            .mockResolvedValueOnce({
                data: {
                    items: [
                        {
                            id: 7,
                            resource_id: 42,
                            identifier: '10.60510/FAILED-IGSN',
                            status: 'failed',
                            operation: 'register',
                            attempts: 1,
                            last_http_status: 401,
                            error_message: 'DataCite authentication failed.',
                            processed_at: '2026-08-30T10:09:00+02:00',
                        },
                    ],
                    pagination: { current_page: 1, last_page: 2, total: 2 },
                },
            })
            .mockResolvedValueOnce({ data: emptyIssues });
        mockPost.mockResolvedValueOnce({ data: { run: resumedRun } });
        const user = userEvent.setup();

        render(<IgsnRegistrationRunModal open onOpenChange={vi.fn()} initialRun={pausedRun} />);

        expect(await screen.findByText('10.60510/FAILED-IGSN')).toBeInTheDocument();
        expect(screen.getByText('Issues (2)')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /Resume/ }));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                `/igsns/batch-register/${baseRun.id}/resume`,
                {},
                expect.objectContaining({ headers: expect.any(Object) }),
            );
        });
        expect(mockToastSuccess).toHaveBeenCalledWith('IGSN registration queued.');
    });

    it('requests cancellation without closing the dialog', async () => {
        const cancellationRequested: IgsnRegistrationRun = {
            ...baseRun,
            status: 'cancel_requested',
            can_cancel: false,
        };
        mockGet.mockResolvedValueOnce({ data: { run: baseRun } });
        mockPost.mockResolvedValueOnce({ data: { run: cancellationRequested } });
        const onOpenChange = vi.fn();
        const user = userEvent.setup();

        render(<IgsnRegistrationRunModal open onOpenChange={onOpenChange} initialRun={baseRun} />);

        await user.click(await screen.findByRole('button', { name: 'Request cancellation' }));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                `/igsns/batch-register/${baseRun.id}/cancel`,
                {},
                expect.objectContaining({ headers: expect.any(Object) }),
            );
        });
        expect(onOpenChange).not.toHaveBeenCalled();
        expect(mockToastSuccess).toHaveBeenCalledWith('Cancellation requested.');
    });

    it('loads terminal issues and notifies the page once when registration completes', async () => {
        const completedRun: IgsnRegistrationRun = {
            ...baseRun,
            status: 'completed',
            processed: 1000,
            registered: 925,
            updated: 70,
            completed_at: '2026-08-30T10:20:00+02:00',
            can_cancel: false,
            can_retry_failed: true,
        };
        mockGet.mockResolvedValueOnce({ data: { run: completedRun } }).mockResolvedValueOnce({
            data: {
                items: [
                    {
                        id: 8,
                        resource_id: 43,
                        identifier: '10.60510/INVALID-IGSN',
                        status: 'failed',
                        operation: 'register',
                        attempts: 1,
                        last_http_status: 422,
                        error_message: 'DataCite rejected the metadata.',
                        processed_at: '2026-08-30T10:19:00+02:00',
                    },
                ],
                pagination: { current_page: 1, last_page: 1, total: 1 },
            },
        });
        mockPost.mockResolvedValueOnce({
            data: {
                run: {
                    ...completedRun,
                    status: 'queued',
                    processed: 995,
                    failed: 0,
                    completed_at: null,
                    can_cancel: true,
                    can_retry_failed: false,
                },
            },
        });
        const onTerminal = vi.fn();
        const user = userEvent.setup();

        render(<IgsnRegistrationRunModal open onOpenChange={vi.fn()} initialRun={completedRun} onTerminal={onTerminal} />);

        expect(await screen.findByText('10.60510/INVALID-IGSN')).toBeInTheDocument();
        expect(onTerminal).toHaveBeenCalledTimes(1);
        await user.click(screen.getByRole('button', { name: /Retry failed/ }));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                `/igsns/batch-register/${baseRun.id}/retry-failed`,
                {},
                expect.objectContaining({ headers: expect.any(Object) }),
            );
        });
        expect(mockToastSuccess).toHaveBeenCalledWith('IGSN registration queued.');
    });

    it('shows a server status error and still allows the dialog to close', async () => {
        const requestError = Object.assign(new Error('Request failed'), {
            isAxiosError: true,
            response: { data: { message: 'Registration status is temporarily unavailable.' } },
        });
        mockGet.mockRejectedValueOnce(requestError);
        const onOpenChange = vi.fn();
        const user = userEvent.setup();

        render(<IgsnRegistrationRunModal open onOpenChange={onOpenChange} initialRun={baseRun} />);

        expect(await screen.findByText('Registration status is temporarily unavailable.')).toBeInTheDocument();
        await user.click(screen.getAllByRole('button', { name: 'Close' })[0]);
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('clears a transient polling error after the next successful refresh', async () => {
        vi.useFakeTimers();
        const requestError = Object.assign(new Error('Request failed'), {
            isAxiosError: true,
            response: { data: { message: 'Registration status is temporarily unavailable.' } },
        });
        mockGet.mockRejectedValueOnce(requestError).mockResolvedValue({ data: { run: baseRun } });

        render(<IgsnRegistrationRunModal open onOpenChange={vi.fn()} initialRun={baseRun} />);

        await act(async () => {
            await Promise.resolve();
        });
        expect(screen.getByText('Registration status is temporarily unavailable.')).toBeInTheDocument();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(3000);
        });

        expect(screen.queryByText('Registration status is temporarily unavailable.')).not.toBeInTheDocument();
        expect(screen.queryByText('The registration needs attention')).not.toBeInTheDocument();
    });
});

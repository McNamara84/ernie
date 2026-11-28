import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { installCsrfFixture } from '@tests/vitest/helpers/csrf-fixture';
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

    it('renders the exact formatted JSON, copies it and confirms the batch on Escape', async () => {
        const user = userEvent.setup();
        const run = { ...baseRun, status: 'completed' as const, is_simulation: true, total: 2, processed: 2, failed: 0, cancelled: 0 };
        const items = ['sample-A', 'sample-B'].map((identifier, id) => ({ id, identifier, status: 'preview_ready', operation: 'register' }));
        const firstPayload = { data: { id: 'sample-A' } };
        const payload = {
            data: { id: 'sample-B', attributes: { titles: ['<sample>', 'A "quoted" title'], value: -1.5, checked: true, missing: null } },
        };
        mockGet.mockImplementation(async (url: string) => {
            if (url.endsWith('/items')) return { data: { items, pagination: { last_page: 1 } } };
            if (url.endsWith('/items/0/preview')) return { data: { payload: firstPayload } };
            if (url.endsWith('/items/1/preview')) return { data: { payload } };
            return { data: { run } };
        });
        mockPost.mockResolvedValue({ data: { run: { ...run, preview_confirmed_at: '2026-11-27' } } });
        const close = vi.fn();
        const refresh = vi.fn();
        render(<IgsnRegistrationRunModal open onOpenChange={close} onTerminal={refresh} initialRun={run} />);
        expect(
            await screen.findByText('Test successful! With test mode off, ERNIE would send this JSON to DataCite to register this IGSN.'),
        ).toBeVisible();
        const samples = screen.getByRole('combobox', { name: 'IGSN' });
        expect(samples).toHaveTextContent('sample-A');
        expect(screen.getByLabelText('DataCite JSON request').textContent).toBe(JSON.stringify(firstPayload, null, 2));
        await user.click(samples);
        expect(await screen.findByRole('option', { name: 'sample-B' })).toBeVisible();
        await user.keyboard('{Escape}');
        await waitFor(() => expect(samples).toHaveFocus());
        expect(close).not.toHaveBeenCalled();
        await user.keyboard('{Enter}');
        await user.keyboard('{ArrowDown}');
        await waitFor(() => expect(screen.getByRole('option', { name: 'sample-B' })).toHaveFocus());
        await user.keyboard('{Enter}');
        expect(samples).toHaveTextContent('sample-B');
        const code = await screen.findByLabelText('DataCite JSON request');
        expect(code.textContent).toBe(JSON.stringify(payload, null, 2));
        expect(code.querySelectorAll('span[class]').length).toBeGreaterThan(5);
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/items/1/preview'), expect.anything());
        await user.click(screen.getByRole('button', { name: 'Copy JSON' }));
        expect(await navigator.clipboard.readText()).toBe(code.textContent);
        expect(mockPost).not.toHaveBeenCalled();
        await user.keyboard('{Escape}');
        await waitFor(() => expect(close).toHaveBeenCalledWith(false));
        expect(mockPost).toHaveBeenCalledWith(`/igsns/batch-register/${run.id}/confirm-preview`, {}, expect.anything());
        expect(refresh).toHaveBeenCalledOnce();
    });

    it('selects a ready preview first and shows the error when a failed sample is picked', async () => {
        const user = userEvent.setup();
        const run = { ...baseRun, status: 'completed' as const, is_simulation: true, total: 2, processed: 2, failed: 1 };
        const items = [
            { id: 1, identifier: 'sample-A', status: 'failed', operation: 'register', error_message: 'No landing page configured.' },
            { id: 2, identifier: 'sample-B', status: 'preview_ready', operation: 'update', error_message: null },
        ];
        mockGet.mockImplementation(async (url: string) => {
            if (url.endsWith('/items')) return { data: { items, pagination: { last_page: 1 } } };
            if (url.endsWith('/items/2/preview')) return { data: { payload: { data: { id: 'sample-B' } } } };
            return { data: { run } };
        });
        render(<IgsnRegistrationRunModal open onOpenChange={vi.fn()} initialRun={run} />);

        expect(await screen.findByLabelText('DataCite JSON request')).toHaveTextContent('sample-B');
        const samples = screen.getByRole('combobox', { name: 'IGSN' });
        expect(samples).toHaveTextContent('sample-B');
        await user.click(samples);
        await user.click(await screen.findByRole('option', { name: 'sample-A (failed)' }));
        expect(samples).toHaveTextContent('sample-A (failed)');
        expect(screen.getByRole('alert')).toHaveTextContent('No landing page configured.');
        expect(screen.queryByLabelText('DataCite JSON request')).not.toBeInTheDocument();
        expect(mockGet).not.toHaveBeenCalledWith(expect.stringContaining('/items/1/preview'), expect.anything());
    });

    it('keeps a finished preview open when confirmation fails and allows retry', async () => {
        const run = { ...baseRun, status: 'completed' as const, is_simulation: true, processed: 1, failed: 0, cancelled: 0 };
        mockGet.mockImplementation(async (url: string) => (url.endsWith('/items') ? { data: emptyIssues } : { data: { run } }));
        mockPost.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ data: { run: { ...run, preview_confirmed_at: 'now' } } });
        const close = vi.fn();
        render(<IgsnRegistrationRunModal open onOpenChange={close} initialRun={run} />);
        await userEvent.click(screen.getByRole('button', { name: 'Close preview' }));
        expect(await screen.findByText(/simulation could not be saved/i)).toBeVisible();
        expect(close).not.toHaveBeenCalled();
        await userEvent.click(screen.getByRole('button', { name: 'Close preview' }));
        await waitFor(() => expect(close).toHaveBeenCalledWith(false));
    });

    it('keeps the newly selected run when a slower response for the preceding run arrives', async () => {
        const newer = { ...baseRun, id: 'second-run' };
        let finishEarlier: (value: { data: { run: IgsnRegistrationRun } }) => void = () => {};
        mockGet
            .mockReturnValueOnce(
                new Promise((resolve) => {
                    finishEarlier = resolve;
                }),
            )
            .mockResolvedValueOnce({ data: { run: newer } });
        const onRunChange = vi.fn();
        const props = { open: true, onOpenChange: vi.fn(), onRunChange };
        const { rerender } = render(<IgsnRegistrationRunModal {...props} initialRun={baseRun} />);
        rerender(<IgsnRegistrationRunModal {...props} initialRun={newer} />);
        await waitFor(() => expect(onRunChange).toHaveBeenCalledWith(newer));
        await act(async () => {
            finishEarlier({ data: { run: baseRun } });
        });
        expect(onRunChange).toHaveBeenCalledTimes(1);
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

    it('preserves loaded issues when the parent refreshes the same run', async () => {
        const pausedRun: IgsnRegistrationRun = {
            ...baseRun,
            status: 'paused',
            pause_reason: 'DataCite authentication failed.',
            paused_at: '2026-08-30T10:10:00+02:00',
            can_resume: true,
        };
        const refreshedRun: IgsnRegistrationRun = {
            ...pausedRun,
            processed: 276,
        };
        mockGet.mockResolvedValueOnce({ data: { run: pausedRun } }).mockResolvedValueOnce({
            data: {
                items: [
                    {
                        id: 7,
                        resource_id: 42,
                        identifier: '10.60510/PERSISTENT-ISSUE',
                        status: 'failed',
                        operation: 'register',
                        attempts: 1,
                        last_http_status: 401,
                        error_message: 'DataCite authentication failed.',
                        processed_at: '2026-08-30T10:09:00+02:00',
                    },
                ],
                pagination: { current_page: 2, last_page: 3, total: 3 },
            },
        });
        const onOpenChange = vi.fn();
        const { rerender } = render(<IgsnRegistrationRunModal open onOpenChange={onOpenChange} initialRun={pausedRun} />);

        expect(await screen.findByText('10.60510/PERSISTENT-ISSUE')).toBeInTheDocument();
        expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();

        rerender(<IgsnRegistrationRunModal open onOpenChange={onOpenChange} initialRun={refreshedRun} />);

        expect(screen.getByText('10.60510/PERSISTENT-ISSUE')).toBeInTheDocument();
        expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
        expect(screen.getByText('276 / 1000')).toBeInTheDocument();
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

installCsrfFixture();

import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { DataCiteUrlUpdateModal, type DataCiteUrlUpdateRun } from '@/components/datacite-url-update-modal';

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

const baseRun: DataCiteUrlUpdateRun = {
    id: '0198f7d7-0000-7000-8000-000000000001',
    scope: 'resources',
    scope_label: 'Resources',
    status: 'running',
    test_mode: true,
    datacite_endpoint: 'https://api.test.datacite.org',
    target_base_url: 'https://dataservices.gfz.de',
    total: 12,
    processed: 1,
    updated: 1,
    already_current: 0,
    skipped: 0,
    failed: 0,
    pause_reason: null,
    last_error: null,
    started_at: '2026-08-23T17:00:00+02:00',
    paused_at: null,
    cancelled_at: null,
    completed_at: null,
    created_at: '2026-08-23T16:59:00+02:00',
    can_cancel: true,
    can_resume: false,
    can_retry_failed: false,
};

const preview = {
    scope: 'resources' as const,
    scope_label: 'Resources',
    total: 12,
    sample_count: 1,
    target_base_url: 'https://dataservices.gfz.de',
    test_mode: false,
    datacite_endpoint: 'https://api.datacite.org',
    can_start: true,
    blocking_message: null,
    items: [
        {
            resource_id: 42,
            identifier: '10.5880/example',
            before_url: 'https://ernie.rz-vm499.gfz.de/10.5880/example/landing',
            target_url: 'https://dataservices.gfz.de/10.5880/example/landing',
            datacite_state: 'findable',
            target_reachable: true,
            outcome: 'ready' as const,
            message: null,
        },
    ],
};

describe('DataCiteUrlUpdateModal', () => {
    beforeEach(() => {
        mockGet.mockReset();
        mockPost.mockReset();
        mockToastSuccess.mockReset();
    });

    it('loads and displays the first URL comparisons before requiring confirmation', async () => {
        mockGet.mockResolvedValueOnce({ data: preview });
        mockPost.mockResolvedValueOnce({ data: { run: baseRun } });
        const user = userEvent.setup();

        render(<DataCiteUrlUpdateModal scope="resources" open onOpenChange={vi.fn()} />);

        expect(await screen.findByText('10.5880/example')).toBeInTheDocument();
        expect(screen.getByText(preview.items[0].before_url)).toBeInTheDocument();
        expect(screen.getByText(preview.items[0].target_url)).toBeInTheDocument();
        expect(screen.getByText('DataCite Production')).toBeInTheDocument();
        expect(screen.getByText(/External landing pages are always excluded/)).toBeInTheDocument();
        expect(mockPost).not.toHaveBeenCalled();

        await user.click(screen.getByTestId('datacite-url-update-confirm'));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                '/datacite/landing-page-url-updates',
                { scope: 'resources' },
                expect.objectContaining({ headers: expect.any(Object) }),
            );
        });
        expect(await screen.findByText('Updating DataCite')).toBeInTheDocument();
        expect(mockToastSuccess).toHaveBeenCalledWith('DataCite landing-page URL update started.');
    });

    it('disables confirmation when a preview safety check fails', async () => {
        mockGet.mockResolvedValueOnce({
            data: {
                ...preview,
                can_start: false,
                blocking_message: 'APP_URL must be a valid absolute HTTPS URL before DataCite URLs can be updated.',
            },
        });

        render(<DataCiteUrlUpdateModal scope="resources" open onOpenChange={vi.fn()} />);

        expect(await screen.findByText('Safety check failed')).toBeInTheDocument();
        expect(screen.getByText('APP_URL must be a valid absolute HTTPS URL before DataCite URLs can be updated.')).toBeInTheDocument();
        expect(screen.getByTestId('datacite-url-update-confirm')).toBeDisabled();
    });

    it('restores a paused persistent run, lists issues, and resumes it', async () => {
        const pausedRun: DataCiteUrlUpdateRun = {
            ...baseRun,
            status: 'paused',
            processed: 2,
            skipped: 1,
            pause_reason: 'DataCite authentication or authorization failed.',
            paused_at: '2026-08-23T17:01:00+02:00',
            can_cancel: true,
            can_resume: true,
        };
        const resumedRun: DataCiteUrlUpdateRun = {
            ...pausedRun,
            status: 'queued',
            pause_reason: null,
            paused_at: null,
            can_resume: false,
        };
        mockGet.mockResolvedValueOnce({ data: { run: pausedRun } }).mockResolvedValueOnce({
            data: {
                items: [
                    {
                        id: 7,
                        resource_id: 42,
                        identifier: '10.5880/problem',
                        status: 'skipped_remote_missing',
                        before_url: null,
                        target_url: 'https://dataservices.gfz.de/new',
                        error_message: 'The identifier was not found at DataCite.',
                    },
                ],
            },
        });
        mockPost.mockResolvedValueOnce({ data: { run: resumedRun } });
        const user = userEvent.setup();

        render(<DataCiteUrlUpdateModal scope="resources" open onOpenChange={vi.fn()} initialRun={pausedRun} />);

        expect(await screen.findByText('10.5880/problem')).toBeInTheDocument();
        expect(screen.getByText('The identifier was not found at DataCite.')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /Resume/ }));

        await waitFor(() => {
            expect(mockPost).toHaveBeenCalledWith(
                `/datacite/landing-page-url-updates/${pausedRun.id}/resume`,
                {},
                expect.objectContaining({ headers: expect.any(Object) }),
            );
        });
        expect(await screen.findByText('Queued')).toBeInTheDocument();
    });
});

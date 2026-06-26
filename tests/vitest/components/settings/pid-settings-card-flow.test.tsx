import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { type PidSettingData, PidSettingsCard } from '@/components/settings/pid-settings-card';

const inertiaMocks = vi.hoisted(() => ({
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: inertiaMocks.reload,
    },
    usePage: () => ({
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Admin User',
                    role: 'admin',
                },
            },
        },
    }),
}));

const raidSetting: PidSettingData = {
    type: 'raid',
    displayName: 'RAiD (Research Activity Identifier)',
    isActive: true,
    isElmoActive: true,
    exists: false,
    itemCount: 0,
    lastUpdated: null,
};

describe('PidSettingsCard update flow', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.defineProperty(document, 'cookie', {
            configurable: true,
            writable: true,
            value: 'XSRF-TOKEN=test-token',
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows downloaded RAiD projects immediately after the update job completes', async () => {
        const user = userEvent.setup();
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    localCount: 0,
                    remoteCount: 570,
                    updateAvailable: true,
                    lastUpdated: null,
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    jobId: '00000000-0000-4000-8000-000000000000',
                    type: 'raid',
                    displayName: 'RAiD (Research Activity Identifier)',
                    message: 'Update job started',
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve({
                    status: 'completed',
                    pidType: 'raid',
                    progress: 'Update completed successfully',
                    completedAt: '2026-06-26T03:00:00Z',
                }),
            });
        global.fetch = fetchMock;

        render(
            <PidSettingsCard
                pidSettings={[raidSetting]}
                onActiveChange={vi.fn()}
                onElmoActiveChange={vi.fn()}
            />,
        );

        expect(screen.getByText('Not yet downloaded')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /check for updates/i }));
        await screen.findByRole('button', { name: /update now/i });

        await user.click(screen.getByRole('button', { name: /update now/i }));

        expect(await screen.findByText('Update completed successfully')).toBeInTheDocument();
        await waitFor(() => expect(screen.queryByText('Not yet downloaded')).not.toBeInTheDocument());
        expect(screen.getByText(/570 projects/)).toBeInTheDocument();
    });
});

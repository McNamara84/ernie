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
    let originalFetch: typeof globalThis.fetch;

    beforeEach(() => {
        originalFetch = globalThis.fetch;
        vi.clearAllMocks();
        Object.defineProperty(document, 'cookie', {
            configurable: true,
            writable: true,
            value: 'XSRF-TOKEN=test-token',
        });
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
        vi.restoreAllMocks();
    });

    it('allows PIDINST refresh at equal counts and displays the actual published result', async () => {
        const user = userEvent.setup();
        globalThis.fetch = vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ localCount: 12, remoteCount: 12, updateAvailable: false, comparisonBasis: 'count' }),
            })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ jobId: 'pidinst-job' }) })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    status: 'completed',
                    pidType: 'pid4inst',
                    progress: 'Update completed successfully',
                    itemCount: 11,
                    lastUpdated: '2026-10-29T09:00:00Z',
                    completedAt: '2026-10-29T09:05:00Z',
                }),
            });
        render(
            <PidSettingsCard
                pidSettings={[{ ...raidSetting, type: 'pid4inst', displayName: 'PIDINST', exists: true, itemCount: 12 }]}
                onActiveChange={vi.fn()}
                onElmoActiveChange={vi.fn()}
            />,
        );
        expect(screen.getByRole('button', { name: /update now/i })).toBeEnabled();
        await user.click(screen.getByRole('button', { name: /check for updates/i }));
        expect(await screen.findByText(/metadata freshness has not been checked/i)).toBeInTheDocument();
        expect(screen.queryByText(/Registry is up to date/i)).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /update now/i }));
        expect(await screen.findByText(/11 instruments/i)).toBeInTheDocument();
        expect(screen.queryByText(/12 instruments/i)).not.toBeInTheDocument();
    });

    it('retains the ROR and RAiD update gating when counts match', async () => {
        const user = userEvent.setup();
        globalThis.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ localCount: 10, remoteCount: 10, updateAvailable: false }) });
        render(<PidSettingsCard pidSettings={[{ ...raidSetting, itemCount: 10 }]} onActiveChange={vi.fn()} onElmoActiveChange={vi.fn()} />);
        await user.click(screen.getByRole('button', { name: /check for updates/i }));
        expect(await screen.findByText(/Registry is up to date/i)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /update now/i })).not.toBeInTheDocument();
    });

    it('shows downloaded RAiD projects immediately after the update job completes', async () => {
        const user = userEvent.setup();
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve({
                        localCount: 0,
                        remoteCount: 570,
                        updateAvailable: true,
                        lastUpdated: null,
                    }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve({
                        jobId: '00000000-0000-4000-8000-000000000000',
                        type: 'raid',
                        displayName: 'RAiD (Research Activity Identifier)',
                        message: 'Update job started',
                    }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve({
                        status: 'completed',
                        pidType: 'raid',
                        progress: 'Update completed successfully',
                        completedAt: '2026-06-26T03:00:00Z',
                    }),
            });
        globalThis.fetch = fetchMock as unknown as typeof fetch;

        render(<PidSettingsCard pidSettings={[raidSetting]} onActiveChange={vi.fn()} onElmoActiveChange={vi.fn()} />);

        expect(screen.getByText('Not yet downloaded')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /check for updates/i }));
        await screen.findByRole('button', { name: /update now/i });

        await user.click(screen.getByRole('button', { name: /update now/i }));

        expect(await screen.findByText('Update completed successfully')).toBeInTheDocument();
        await waitFor(() => expect(screen.queryByText('Not yet downloaded')).not.toBeInTheDocument());
        expect(screen.getByText(/570 projects/)).toBeInTheDocument();
    });
});

import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { type PidSettingData, PidSettingsCard } from '@/components/settings/pid-settings-card';

import { renderWithQueryClient as render } from '../../helpers/render-with-query-client';

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: { role: 'admin' } } } }),
}));

const setting: PidSettingData = {
    type: 'pid4inst',
    displayName: 'PID4INST',
    isActive: true,
    isElmoActive: false,
    exists: true,
    itemCount: 2,
    lastUpdated: '2026-01-01T00:00:00Z',
};
const excluded = [{ id: '44jr3-qtj89', reasonCode: 'unregistered_handle' }];
const props = { onActiveChange: vi.fn(), onElmoActiveChange: vi.fn() };
const jsonResponse = (data: unknown) => ({ ok: true, json: async () => data });

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('PID4INST diagnostics', () => {
    it('shows persisted exclusions and pending cache refresh together after a fresh page load', async () => {
        render(<PidSettingsCard {...props} pidSettings={[{ ...setting, excludedCount: 12, exclusions: excluded, refreshPending: true }]} />);
        expect(screen.getByText(/last registry update excluded 12 instruments/)).toBeInTheDocument();
        expect(screen.getByText(/Existing dataset links were preserved/)).toBeInTheDocument();
        expect(screen.getByText(/refreshing the connected editors is still pending/)).toBeInTheDocument();
        await userEvent.click(screen.getByText('Excluded instruments'));
        expect(screen.getByText(/44jr3-qtj89: Handle is not registered/)).toBeVisible();
        expect(screen.getByText('Showing the first 1 excluded instruments.')).toBeVisible();
    });

    it('uses published counts and retains the warning after updated page props arrive', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(jsonResponse({ localCount: 2, remoteCount: 3915, updateAvailable: true }))
                .mockResolvedValueOnce(jsonResponse({ jobId: 'test-job' }))
                .mockResolvedValueOnce(
                    jsonResponse({
                        status: 'completed',
                        pidType: 'pid4inst',
                        progress: 'Update completed successfully',
                        itemCount: 3914,
                        lastUpdated: '2026-11-05T10:00:00Z',
                        excludedCount: 1,
                        exclusions: excluded,
                    }),
                ),
        );
        const { rerender } = render(<PidSettingsCard {...props} pidSettings={[setting]} />);
        await userEvent.click(screen.getByRole('button', { name: 'Check for Updates' }));
        await screen.findByText(/Would you like to update the registry/);
        await userEvent.click(screen.getByRole('button', { name: 'Update Now' }));
        await screen.findByText(/last registry update excluded 1 instrument\./);
        expect(screen.getByText(`${(3914).toLocaleString()} instruments`)).toBeInTheDocument();
        rerender(
            <PidSettingsCard
                {...props}
                pidSettings={[{ ...setting, itemCount: 3914, lastUpdated: '2026-11-05T10:00:00Z', excludedCount: 1, exclusions: excluded }]}
            />,
        );
        expect(screen.getByText(/last registry update excluded 1 instrument\./)).toBeInTheDocument();
        expect(screen.queryByText(`${(3915).toLocaleString()} instruments`)).not.toBeInTheDocument();
    });

    it('keeps the previous published warning and count after a failed retry', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(jsonResponse({ jobId: 'test-job' }))
                .mockResolvedValueOnce(
                    jsonResponse({ status: 'failed', pidType: 'pid4inst', progress: 'Update failed', error: 'Resolver unavailable' }),
                ),
        );
        render(<PidSettingsCard {...props} pidSettings={[{ ...setting, excludedCount: 1, exclusions: excluded }]} />);
        await userEvent.click(screen.getByRole('button', { name: 'Update Now' }));
        await screen.findByText(/Update failed: Resolver unavailable/);
        expect(screen.getByText(/last registry update excluded 1 instrument\./)).toBeInTheDocument();
        expect(screen.getByText('2 instruments')).toBeInTheDocument();
    });

    it('clears an old warning following a successful import without exclusions', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce(jsonResponse({ jobId: 'test-job' }))
                .mockResolvedValueOnce(
                    jsonResponse({
                        status: 'completed',
                        pidType: 'pid4inst',
                        progress: 'Update completed successfully',
                        itemCount: 3,
                        excludedCount: 0,
                        exclusions: [],
                    }),
                ),
        );
        render(<PidSettingsCard {...props} pidSettings={[{ ...setting, excludedCount: 1, exclusions: excluded }]} />);
        await userEvent.click(screen.getByRole('button', { name: 'Update Now' }));
        await screen.findByText('Update completed successfully');
        expect(screen.queryByText(/last registry update excluded/)).not.toBeInTheDocument();
        expect(screen.getByText('3 instruments')).toBeInTheDocument();
    });

    it('describes other invalid metadata without claiming that its Handle is unregistered', async () => {
        render(
            <PidSettingsCard
                {...props}
                pidSettings={[{ ...setting, excludedCount: 1, exclusions: [{ id: 'invalid', reasonCode: 'invalid_record' }] }]}
            />,
        );
        await userEvent.click(screen.getByText('Excluded instruments'));
        expect(screen.getByText('invalid: Instrument metadata could not be validated.')).toBeVisible();
        expect(screen.queryByText(/Handle is not registered/)).not.toBeInTheDocument();
    });

    it('renders legacy settings without an exclusion warning', () => {
        render(<PidSettingsCard {...props} pidSettings={[setting]} />);
        expect(screen.queryByText(/last registry update excluded/)).not.toBeInTheDocument();
    });
});

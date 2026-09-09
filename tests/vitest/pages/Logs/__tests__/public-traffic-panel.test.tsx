import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@tests/vitest/utils/render';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PublicTrafficPanel, { type PublicTrafficCell, type PublicTrafficResponse } from '@/pages/Logs/components/public-traffic-panel';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

const cells: PublicTrafficCell[] = Array.from({ length: 168 }, (_, index) => ({
    weekday: Math.floor(index / 24) + 1,
    hour: index % 24,
    combinedAverage: index,
    landingPageAverage: index / 2,
    portalAverage: index / 3,
    sampleCount: 6,
}));

const response: PublicTrafficResponse = {
    period: '12w',
    periodWeeks: 12,
    timezone: 'Europe/Berlin',
    requestedFrom: '2026-06-16T12:00:00+00:00',
    effectiveFrom: '2026-06-16T12:00:00+00:00',
    to: '2026-09-08T12:00:00+00:00',
    status: 'available',
    collectionStartedAt: '2026-06-16T12:00:00+00:00',
    lastCompleteBucketAt: '2026-09-08T11:00:00+00:00',
    coverage: {
        completeHours: 2000,
        excludedHours: 4,
        minimumCellSampleCount: 6,
        lowSampleWarning: false,
    },
    cells,
    quietest: cells.slice(0, 3),
    busiest: [...cells].reverse().slice(0, 3),
};

const mockedGet = vi.mocked(axios.get);

describe('PublicTrafficPanel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockedGet.mockResolvedValue({ data: response });
    });

    it('loads the twelve-week view and renders rankings plus an accessible 168-cell heatmap', async () => {
        render(<PublicTrafficPanel />);

        expect(screen.getByLabelText('Loading public traffic')).toBeInTheDocument();
        expect(await screen.findByText('Quietest windows')).toBeInTheDocument();
        expect(screen.getByText('Busiest windows')).toBeInTheDocument();
        expect(screen.getByText(/Europe\/Berlin/)).toBeInTheDocument();
        expect(screen.getByText(/4 unavailable or incomplete hours excluded/)).toBeInTheDocument();

        const heatmap = screen.getByRole('table', { name: 'Public traffic heatmap by weekday and hour' });
        const heatmapCells = within(heatmap).getAllByRole('button');
        expect(heatmapCells).toHaveLength(168);
        expect(heatmapCells[0]).toHaveClass('bg-emerald-50');
        expect(heatmapCells[1]).toHaveClass('bg-sky-100');
        expect(heatmapCells[34]).toHaveClass('bg-sky-200');
        expect(heatmapCells[120]).toHaveClass('bg-blue-600');
        expect(heatmapCells[167]).toHaveClass('bg-blue-700');

        const legend = screen.getByLabelText('Traffic intensity legend');
        const legendSwatches = legend.querySelectorAll('span[aria-hidden="true"]');
        expect(legendSwatches).toHaveLength(7);
        expect(legendSwatches[1]).toHaveClass('bg-sky-100');
        expect(legendSwatches[2]).toHaveClass('bg-sky-200');
        expect(within(heatmap).getByRole('button', { name: /Monday, 00:00–01:00: 0 estimated visitors/ })).toBeInTheDocument();
        expect(
            within(heatmap).getByRole('button', {
                name: /Monday, 01:00–02:00: 1 estimated visitors on average; 0.5 landing pages; 0.33 portal; 6 observations/,
            }),
        ).toBeInTheDocument();
        expect(mockedGet).toHaveBeenCalledWith('/logs/public-traffic', expect.objectContaining({ params: { period: '12w' } }));
    });

    it('explains the short-lived cache identifier and persistent privacy boundary', async () => {
        render(<PublicTrafficPanel />);

        const privacyNotice = await screen.findByText(/short-lived HMAC identifier only in shared-cache key names/);

        expect(privacyNotice.textContent).toMatch(
            /Raw IP addresses and user agents are not stored, and only hourly aggregate counts persist in MySQL/,
        );
    });

    it('uses the API-provided timezone in the subtitle', async () => {
        mockedGet.mockResolvedValue({ data: { ...response, timezone: 'America/New_York' } });

        render(<PublicTrafficPanel />);

        expect(await screen.findByText(/Landing pages and DOI\/IGSN portals · America\/New_York/)).toBeInTheDocument();
        expect(screen.queryByText(/Landing pages and DOI\/IGSN portals · Europe\/Berlin/)).not.toBeInTheDocument();
    });

    it.each([
        ['4w', 'Show last 4 weeks', 4],
        ['52w', 'Show last 52 weeks', 52],
    ] as const)('switches to the %s period', async (period, accessibleName, periodWeeks) => {
        const user = userEvent.setup();
        mockedGet.mockResolvedValueOnce({ data: response }).mockResolvedValueOnce({ data: { ...response, period, periodWeeks } });

        render(<PublicTrafficPanel />);
        await screen.findByText('Available');
        await user.click(screen.getByRole('radio', { name: accessibleName }));

        await waitFor(() => {
            expect(mockedGet).toHaveBeenLastCalledWith('/logs/public-traffic', expect.objectContaining({ params: { period } }));
        });
    });

    it('shows the per-surface values in a heatmap tooltip', async () => {
        const user = userEvent.setup();
        render(<PublicTrafficPanel />);

        const heatmap = await screen.findByRole('table', { name: 'Public traffic heatmap by weekday and hour' });
        const cell = within(heatmap).getAllByRole('button')[1];
        await user.hover(cell);

        expect(await screen.findByText('Combined: 1')).toBeInTheDocument();
        expect(screen.getByText('Landing pages: 0.5')).toBeInTheDocument();
        expect(screen.getByText('Portal: 0.33')).toBeInTheDocument();
        expect(screen.getByText('Observed hours: 6')).toBeInTheDocument();
    });

    it('shows collecting coverage without premature rankings and distinguishes missing cells', async () => {
        mockedGet.mockResolvedValue({
            data: {
                ...response,
                status: 'collecting',
                cells: cells.map((cell, index) =>
                    index === 0 ? { ...cell, combinedAverage: null, landingPageAverage: null, portalAverage: null, sampleCount: 0 } : cell,
                ),
                quietest: [],
                busiest: [],
                coverage: { ...response.coverage, minimumCellSampleCount: 0, lowSampleWarning: true },
            },
        });

        render(<PublicTrafficPanel />);

        expect(await screen.findByText(/Weekly recommendations will appear/)).toBeInTheDocument();
        expect(screen.queryByText('Quietest windows')).not.toBeInTheDocument();
        const missingCell = screen.getByRole('button', { name: 'Monday, 00:00–01:00: no complete observations' });
        expect(missingCell).toHaveTextContent('—');
        expect(missingCell).toHaveClass('bg-muted/60');
    });

    it('shows the low-sample warning after rankings become available', async () => {
        mockedGet.mockResolvedValue({
            data: {
                ...response,
                coverage: { ...response.coverage, minimumCellSampleCount: 1, lowSampleWarning: true },
            },
        });

        render(<PublicTrafficPanel />);

        expect(await screen.findByText(/fewer than four observations/)).toBeInTheDocument();
        expect(screen.getByText('Quietest windows')).toBeInTheDocument();
    });

    it('renders an honest disabled state without a heatmap', async () => {
        mockedGet.mockResolvedValue({
            data: {
                ...response,
                status: 'disabled',
                effectiveFrom: null,
                cells: cells.map((cell) => ({ ...cell, combinedAverage: null, landingPageAverage: null, portalAverage: null, sampleCount: 0 })),
                quietest: [],
                busiest: [],
            },
        });

        render(<PublicTrafficPanel />);

        expect(await screen.findByText('Public traffic collection is disabled in this environment.')).toBeInTheDocument();
        expect(screen.queryByRole('table', { name: 'Public traffic heatmap by weekday and hour' })).not.toBeInTheDocument();
    });

    it('offers a retry after a request error', async () => {
        const user = userEvent.setup();
        mockedGet.mockRejectedValueOnce(new Error('Network error')).mockResolvedValueOnce({ data: response });

        render(<PublicTrafficPanel />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Public traffic could not be loaded.');
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByText('Quietest windows')).toBeInTheDocument();
        expect(mockedGet).toHaveBeenCalledTimes(2);
    });

    it('reloads the selected period when the parent refresh key changes', async () => {
        const { rerender } = render(<PublicTrafficPanel refreshKey={0} />);
        await screen.findByText('Available');

        rerender(<PublicTrafficPanel refreshKey={1} />);

        await waitFor(() => expect(mockedGet).toHaveBeenCalledTimes(2));
        expect(mockedGet).toHaveBeenLastCalledWith('/logs/public-traffic', expect.objectContaining({ params: { period: '12w' } }));
    });
});

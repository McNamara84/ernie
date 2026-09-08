import userEvent from '@testing-library/user-event';
import { render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import SystemMetricsPanel, { type SystemMetricsResponse } from '@/pages/Logs/components/system-metrics-panel';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

vi.mock('recharts', async () => {
    const actual = await vi.importActual<typeof import('recharts')>('recharts');

    return {
        ...actual,
        ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
            <div data-testid="responsive-container" style={{ width: 600, height: 256 }}>
                {children}
            </div>
        ),
    };
});

const dayResponse: SystemMetricsResponse = {
    period: 'day',
    from: '2026-09-05T12:00:00+00:00',
    to: '2026-09-06T12:00:00+00:00',
    bucket_minutes: 5,
    status: 'available',
    latest: {
        recorded_at: '2026-09-06T12:00:00+00:00',
        cpu_usage_percent: 37.25,
        memory_usage_percent: 62.5,
        memory_used_bytes: 10 * 1024 ** 3,
        memory_total_bytes: 16 * 1024 ** 3,
    },
    samples: [
        {
            recorded_at: '2026-09-06T11:55:00+00:00',
            cpu_usage_percent: 25,
            memory_usage_percent: 60,
        },
        {
            recorded_at: '2026-09-06T12:00:00+00:00',
            cpu_usage_percent: 37.25,
            memory_usage_percent: 62.5,
        },
    ],
};

const mockedGet = vi.mocked(axios.get);

describe('SystemMetricsPanel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockedGet.mockResolvedValue({ data: dayResponse });
    });

    it('loads and renders VM-wide CPU and memory charts with current values', async () => {
        render(<SystemMetricsPanel />);

        expect(screen.getByLabelText('Loading server utilization')).toBeInTheDocument();
        expect(await screen.findByText('CPU utilization')).toBeInTheDocument();
        expect(screen.getByText('Memory utilization')).toBeInTheDocument();
        expect(screen.getByLabelText('CPU utilization: 37.3 percent')).toHaveTextContent('37.3%');
        expect(screen.getByLabelText('Memory utilization: 62.5 percent')).toHaveTextContent('62.5%');
        expect(screen.getByText('10 GiB of 16 GiB')).toBeInTheDocument();
        expect(screen.getByText(/Entire host VM/)).toBeInTheDocument();
        expect(screen.getAllByTestId('responsive-container')).toHaveLength(2);
        expect(screen.getByText('Live')).toBeInTheDocument();
        expect(mockedGet).toHaveBeenCalledWith('/logs/system-metrics', expect.objectContaining({ params: { period: 'day' } }));
    });

    it('switches both charts to the seven-day period', async () => {
        const user = userEvent.setup();
        const weekResponse = { ...dayResponse, period: 'week' as const, bucket_minutes: 30 };
        mockedGet.mockResolvedValueOnce({ data: dayResponse }).mockResolvedValueOnce({ data: weekResponse });

        render(<SystemMetricsPanel />);
        await screen.findByText('Live');
        await user.click(screen.getByRole('radio', { name: 'Show last 7 days' }));

        await waitFor(() => {
            expect(mockedGet).toHaveBeenLastCalledWith('/logs/system-metrics', expect.objectContaining({ params: { period: 'week' } }));
        });
    });

    it.each([
        ['disabled' as const, 'Disabled', 'Host VM monitoring is not enabled'],
        ['collecting' as const, 'Collecting', 'CPU data becomes available after two consecutive samples'],
        ['stale' as const, 'Stale', 'latest sample is older than three minutes'],
    ])('renders the %s collection state', async (status, label, description) => {
        mockedGet.mockResolvedValue({
            data: {
                ...dayResponse,
                status,
                latest: status === 'collecting' ? { ...dayResponse.latest!, cpu_usage_percent: null } : dayResponse.latest,
            },
        });

        render(<SystemMetricsPanel />);

        expect(await screen.findByText(label)).toBeInTheDocument();
        expect(screen.getByText(new RegExp(description, 'i'))).toBeInTheDocument();
    });

    it('shows honest empty states when no measurements exist yet', async () => {
        mockedGet.mockResolvedValue({
            data: {
                ...dayResponse,
                status: 'collecting',
                latest: null,
                samples: dayResponse.samples.map((sample) => ({
                    ...sample,
                    cpu_usage_percent: null,
                    memory_usage_percent: null,
                })),
            },
        });

        render(<SystemMetricsPanel />);

        expect(await screen.findAllByText('No measurements are available for this period yet.')).toHaveLength(2);
        expect(screen.getByLabelText('CPU utilization: unavailable percent')).toHaveTextContent('—');
    });

    it('offers a retry after a request error', async () => {
        const user = userEvent.setup();
        mockedGet.mockRejectedValueOnce(new Error('Network failure')).mockResolvedValueOnce({ data: dayResponse });

        render(<SystemMetricsPanel />);

        expect(await screen.findByRole('alert')).toHaveTextContent('Server utilization could not be loaded.');
        await user.click(screen.getByRole('button', { name: 'Try again' }));

        expect(await screen.findByText('Live')).toBeInTheDocument();
        expect(mockedGet).toHaveBeenCalledTimes(2);
    });

    it('reloads the selected period when the parent refresh key changes', async () => {
        const { rerender } = render(<SystemMetricsPanel refreshKey={0} />);
        await screen.findByText('Live');

        rerender(<SystemMetricsPanel refreshKey={1} />);

        await waitFor(() => expect(mockedGet).toHaveBeenCalledTimes(2));
        expect(mockedGet).toHaveBeenLastCalledWith('/logs/system-metrics', expect.objectContaining({ params: { period: 'day' } }));
    });
});

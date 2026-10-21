import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { type UsageData, UsageSection } from '@/pages/LandingPages/components/UsageSection';
import { normalizeIgsnColumnOrders, normalizeResourceColumnOrders, RESOURCE_SECTIONS } from '@/pages/LandingPages/lib/section-catalog';

vi.mock('@/pages/LandingPages/hooks/useFadeInOnScroll', () => ({ useFadeInOnScroll: () => ({ current: null }) }));
vi.mock('recharts', () => ({
    ResponsiveContainer: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    LineChart: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    Line: ({ name }: { name: string }) => <span data-testid="chart-series">{name}</span>,
    CartesianGrid: () => null,
    Legend: () => null,
    Tooltip: () => null,
    XAxis: () => null,
    YAxis: () => null,
}));

const usage: UsageData = {
    state: 'ready',
    startedAt: '2026-01-15T10:00:00+00:00',
    lastAggregatedAt: '2026-03-02T00:15:00+00:00',
    dataThrough: '2026-03-01',
    metricsRevision: 3,
    paused: false,
    incompleteDays: [],
    methodologyUrl: '/usage-statistics',
    views: 12345,
    downloads: 2345,
    months: [
        { month: '2026-01', views: 100, downloads: 20, partial: true, incomplete: false },
        { month: '2026-02', views: 0, downloads: 0, partial: false, incomplete: false },
        { month: '2026-03', views: 1, downloads: 1, partial: true, incomplete: true },
    ],
};

describe('UsageSection', () => {
    it('shows lifetime totals, actual aggregation dates, distinguishable series and equivalent table values', () => {
        render(<UsageSection usage={usage} />);
        expect(screen.getByText('12,345')).toBeInTheDocument();
        expect(screen.getByText('2,345')).toBeInTheDocument();
        expect(screen.getByText(/Data through 2026-03-01/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'How we count' })).toHaveAttribute('href', '/usage-statistics');
        expect(screen.getAllByTestId('chart-series')).toHaveLength(2);
        fireEvent.click(screen.getByText('Monthly data table'));
        const table = screen.getByRole('table');
        expect(within(table).getByRole('columnheader', { name: 'Downloads' })).toBeInTheDocument();
        expect(within(table).getByText('100')).toBeInTheDocument();
        expect(within(table).getByText('Complete month')).toBeInTheDocument();
        expect(within(table).getByText('Missing observations')).toBeInTheDocument();
    });

    it('omits all download UI for physical samples', () => {
        render(<UsageSection usage={{ ...usage, downloads: null, months: usage.months.map((month) => ({ ...month, downloads: null })) }} />);
        expect(screen.queryByText(/download/i)).not.toBeInTheDocument();
        expect(screen.getAllByTestId('chart-series')).toHaveLength(1);
        fireEvent.click(screen.getByText('Monthly data table'));
        expect(screen.queryByRole('columnheader', { name: 'Downloads' })).not.toBeInTheDocument();
    });

    it.each([
        ['inactive', 'Usage collection has not started yet.'],
        ['placeholder', 'Usage becomes available after publication and aggregation.'],
        ['pending', 'Usage collection has started. The first daily aggregation is pending.'],
    ] as const)('explains the %s state without a misleading empty chart', (state, message) => {
        render(<UsageSection usage={{ ...usage, state }} />);
        expect(screen.getByText(message)).toBeInTheDocument();
        expect(screen.queryByTestId('chart-series')).not.toBeInTheDocument();
    });

    it('labels incomplete observations and provides a useful measured-zero state', () => {
        render(
            <UsageSection
                usage={{
                    ...usage,
                    state: 'incomplete',
                    paused: true,
                    views: 0,
                    downloads: 0,
                    months: [{ month: '2026-03', views: 0, downloads: 0, partial: true, incomplete: true }],
                }}
            />,
        );
        expect(screen.getByRole('status')).toHaveTextContent('Totals may be incomplete');
        expect(screen.getByText('Collection is currently paused.')).toBeInTheDocument();
        expect(screen.getByText('No usage recorded in the displayed months.')).toBeInTheDocument();
        expect(screen.queryByTestId('chart-series')).not.toBeInTheDocument();
    });

    it('shows a pause before the first daily aggregation as well', () => {
        render(<UsageSection usage={{ ...usage, state: 'pending', paused: true }} />);
        expect(screen.getByText('Collection is currently paused.')).toBeInTheDocument();
        expect(screen.queryByTestId('chart-series')).not.toBeInTheDocument();
    });
});

describe('resource visibility normalization', () => {
    it('preserves explicit hiding for every DOI module including citation and licenses', () => {
        expect(normalizeResourceColumnOrders([], [], RESOURCE_SECTIONS)).toEqual({ left: [], right: [], hidden: RESOURCE_SECTIONS });
    });

    it('restores usage to the right of old layouts while respecting explicit hiding', () => {
        expect(normalizeResourceColumnOrders([], []).right.at(-1)).toBe('usage');
        expect(normalizeIgsnColumnOrders([], []).right).toContain('usage');
        expect(normalizeIgsnColumnOrders([], [], ['usage', 'version_notice']).hidden).toContain('usage');
        expect(normalizeIgsnColumnOrders([], [], ['usage', 'version_notice']).right).toContain('version_notice');
    });
});

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PidUsageChart from '@/components/statistics/pid-usage-chart';

// Mock ResponsiveContainer for jsdom compatibility
vi.mock('recharts', async () => {
    const actual = await vi.importActual('recharts');
    return {
        ...actual,
        ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
            <div data-testid="responsive-container" style={{ width: 400, height: 300 }}>
                {children}
            </div>
        ),
    };
});

describe('PidUsageChart', () => {
    const mockData = [
        { type: 'DOI', count: 850, percentage: 56.67 },
        { type: 'ORCID', count: 350, percentage: 23.33 },
        { type: 'ROR', count: 200, percentage: 13.33 },
        { type: 'ISNI', count: 100, percentage: 6.67 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<PidUsageChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts PieChart wrapper', () => {
        const { container } = render(<PidUsageChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<PidUsageChart data={[]} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders the summary table with PID types', () => {
        render(<PidUsageChart data={mockData} />);

        expect(screen.getByText('DOI')).toBeInTheDocument();
        expect(screen.getByText('ORCID')).toBeInTheDocument();
        expect(screen.getByText('ROR')).toBeInTheDocument();
        expect(screen.getByText('ISNI')).toBeInTheDocument();
    });

    it('displays formatted counts in the table', () => {
        render(<PidUsageChart data={mockData} />);

        expect(screen.getByText('850')).toBeInTheDocument();
        expect(screen.getByText('350')).toBeInTheDocument();
    });

    it('displays formatted percentages in the table', () => {
        render(<PidUsageChart data={mockData} />);

        expect(screen.getByText('56.67%')).toBeInTheDocument();
        expect(screen.getByText('23.33%')).toBeInTheDocument();
    });

    it('renders color indicators for each type', () => {
        const { container } = render(<PidUsageChart data={mockData} />);

        // Check for color boxes in the table
        const colorBoxes = container.querySelectorAll('.h-3.w-3.rounded-sm');
        expect(colorBoxes.length).toBeGreaterThan(0);
    });

    it('handles many PID types with color cycling', () => {
        const manyTypes = Array.from({ length: 50 }, (_, i) => ({
            type: `PID Type ${i + 1}`,
            count: 100 - i,
            percentage: 2.0,
        }));

        const { container } = render(<PidUsageChart data={manyTypes} />);

        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });
});

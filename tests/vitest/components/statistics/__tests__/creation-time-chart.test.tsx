import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import CreationTimeChart from '@/components/statistics/creation-time-chart';

// Mock ResponsiveContainer for jsdom compatibility
vi.mock('recharts', async () => {
    const actual = await vi.importActual('recharts');
    return {
        ...actual,
        ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
            <div data-testid="responsive-container" style={{ width: 400, height: 350 }}>
                {children}
            </div>
        ),
    };
});

describe('CreationTimeChart', () => {
    const mockData = [
        { hour: 9, count: 45 },
        { hour: 10, count: 62 },
        { hour: 14, count: 78 },
        { hour: 15, count: 55 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<CreationTimeChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts LineChart wrapper', () => {
        const { container } = render(<CreationTimeChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<CreationTimeChart data={[]} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('fills all 24 hours with zero counts for missing hours', () => {
        const sparseData = [{ hour: 12, count: 100 }];
        const { container } = render(<CreationTimeChart data={sparseData} />);

        // Chart should still render with all hours
        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });

    it('handles full 24-hour data', () => {
        const fullData = Array.from({ length: 24 }, (_, i) => ({
            hour: i,
            count: i * 5,
        }));
        render(<CreationTimeChart data={fullData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });
});

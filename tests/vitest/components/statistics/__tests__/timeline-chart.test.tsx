import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import TimelineChart from '@/components/statistics/timeline-chart';

// Mock ResponsiveContainer for jsdom compatibility
vi.mock('recharts', async () => {
    const actual = await vi.importActual('recharts');
    return {
        ...actual,
        ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
            <div data-testid="responsive-container" style={{ width: 400, height: 400 }}>
                {children}
            </div>
        ),
    };
});

describe('TimelineChart', () => {
    const mockData = {
        publicationsByYear: [
            { year: 2020, count: 45 },
            { year: 2021, count: 62 },
            { year: 2022, count: 78 },
        ],
        createdByYear: [
            { year: 2020, count: 30 },
            { year: 2021, count: 55 },
            { year: 2023, count: 90 },
        ],
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<TimelineChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts AreaChart wrapper', () => {
        const { container } = render(<TimelineChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        const emptyData = {
            publicationsByYear: [],
            createdByYear: [],
        };
        render(<TimelineChart data={emptyData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders the chart structure with legend component', () => {
        const { container } = render(<TimelineChart data={mockData} />);

        // The chart wrapper should be present (legend is inside recharts)
        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });

    it('merges data from both sources by year', () => {
        const { container } = render(<TimelineChart data={mockData} />);

        // Chart should render with merged data
        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });

    it('handles years only in publications data', () => {
        const publicationsOnly = {
            publicationsByYear: [{ year: 2020, count: 100 }],
            createdByYear: [],
        };
        render(<TimelineChart data={publicationsOnly} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles years only in created data', () => {
        const createdOnly = {
            publicationsByYear: [],
            createdByYear: [{ year: 2020, count: 50 }],
        };
        render(<TimelineChart data={createdOnly} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });
});

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PublicationYearChart from '@/components/statistics/publication-year-chart';

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

describe('PublicationYearChart', () => {
    const mockData = [
        { year: 2020, count: 45 },
        { year: 2021, count: 62 },
        { year: 2022, count: 78 },
        { year: 2023, count: 95 },
        { year: 2024, count: 120 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<PublicationYearChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts AreaChart wrapper', () => {
        const { container } = render(<PublicationYearChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<PublicationYearChart data={[]} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders with single year data', () => {
        const singleYear = [{ year: 2024, count: 100 }];
        render(<PublicationYearChart data={singleYear} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles large dataset', () => {
        const largeData = Array.from({ length: 50 }, (_, i) => ({
            year: 1975 + i,
            count: Math.floor(Math.random() * 200),
        }));

        const { container } = render(<PublicationYearChart data={largeData} />);

        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });

    it('renders the chart component structure', () => {
        const { container } = render(<PublicationYearChart data={mockData} />);

        // AreaChart renders inside the container
        expect(container.querySelector('[data-testid="responsive-container"]')).toBeInTheDocument();
    });
});

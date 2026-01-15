import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import IsSupplementToChart from '@/components/statistics/is-supplement-to-chart';

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

describe('IsSupplementToChart', () => {
    const mockData = {
        withIsSupplementTo: 750,
        withoutIsSupplementTo: 250,
        percentageWith: 75.0,
        percentageWithout: 25.0,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<IsSupplementToChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts PieChart wrapper', () => {
        const { container } = render(<IsSupplementToChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('displays legend items', () => {
        render(<IsSupplementToChart data={mockData} />);

        expect(screen.getByText('With IsSupplementTo')).toBeInTheDocument();
        expect(screen.getByText('Without IsSupplementTo')).toBeInTheDocument();
    });

    it('handles zero values gracefully', () => {
        const zeroData = {
            withIsSupplementTo: 0,
            withoutIsSupplementTo: 0,
            percentageWith: 0,
            percentageWithout: 0,
        };
        render(<IsSupplementToChart data={zeroData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles 100% with supplement to', () => {
        const allWithData = {
            withIsSupplementTo: 1000,
            withoutIsSupplementTo: 0,
            percentageWith: 100,
            percentageWithout: 0,
        };
        render(<IsSupplementToChart data={allWithData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles 100% without supplement to', () => {
        const allWithoutData = {
            withIsSupplementTo: 0,
            withoutIsSupplementTo: 500,
            percentageWith: 0,
            percentageWithout: 100,
        };
        render(<IsSupplementToChart data={allWithoutData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });
});

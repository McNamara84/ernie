import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import CuratorChart from '@/components/statistics/curator-chart';

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

describe('CuratorChart', () => {
    const mockData = [
        { name: 'John Doe', count: 45 },
        { name: 'Jane Smith', count: 32 },
        { name: 'Bob Johnson', count: 28 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<CuratorChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders recharts BarChart wrapper', () => {
        const { container } = render(<CuratorChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<CuratorChart data={[]} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders with single curator', () => {
        const singleCurator = [{ name: 'Single Curator', count: 100 }];
        render(<CuratorChart data={singleCurator} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('handles many curators with color cycling', () => {
        const manyCurators = Array.from({ length: 15 }, (_, i) => ({
            name: `Curator ${i + 1}`,
            count: (i + 1) * 5,
        }));

        const { container } = render(<CuratorChart data={manyCurators} />);

        expect(container.querySelector('.recharts-wrapper')).toBeInTheDocument();
    });
});

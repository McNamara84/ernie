import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ResourceTypeChart from '@/components/statistics/resource-type-chart';

// Mock ResizeObserver for ResponsiveContainer
vi.mock('recharts', async () => {
    const actual = await vi.importActual('recharts');
    return {
        ...actual,
        ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
            <div data-testid="responsive-container" style={{ width: 400, height: 250 }}>
                {children}
            </div>
        ),
    };
});

describe('ResourceTypeChart', () => {
    const mockData = [
        { type: 'Dataset', count: 150 },
        { type: 'Software', count: 75 },
        { type: 'Text', count: 50 },
        { type: 'Image', count: 25 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<ResourceTypeChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('renders a table with all resource types', () => {
        render(<ResourceTypeChart data={mockData} />);

        expect(screen.getByText('Dataset')).toBeInTheDocument();
        expect(screen.getByText('Software')).toBeInTheDocument();
        expect(screen.getByText('Text')).toBeInTheDocument();
        expect(screen.getByText('Image')).toBeInTheDocument();
    });

    it('displays counts in localized format', () => {
        render(<ResourceTypeChart data={mockData} />);

        // German locale uses . as thousand separator
        expect(screen.getByText('150')).toBeInTheDocument();
        expect(screen.getByText('75')).toBeInTheDocument();
        expect(screen.getByText('50')).toBeInTheDocument();
        expect(screen.getByText('25')).toBeInTheDocument();
    });

    it('renders correct number of table rows', () => {
        render(<ResourceTypeChart data={mockData} />);

        const rows = screen.getAllByRole('row');
        expect(rows).toHaveLength(4);
    });

    it('renders color indicators for each type', () => {
        const { container } = render(<ResourceTypeChart data={mockData} />);

        // Check for color boxes (3x3 rounded squares)
        const colorBoxes = container.querySelectorAll('.h-3.w-3.rounded-sm');
        expect(colorBoxes).toHaveLength(4);
    });

    it('handles empty data gracefully', () => {
        render(<ResourceTypeChart data={[]} />);

        // Should still render the container
        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
        
        // No table rows
        expect(screen.queryAllByRole('row')).toHaveLength(0);
    });

    it('handles large numbers with locale formatting', () => {
        const largeData = [
            { type: 'Dataset', count: 1500000 },
        ];
        render(<ResourceTypeChart data={largeData} />);

        // German locale formats 1500000 as "1.500.000"
        expect(screen.getByText('1.500.000')).toBeInTheDocument();
    });

    it('cycles through colors for more than 16 types', () => {
        const manyTypes = Array.from({ length: 20 }, (_, i) => ({
            type: `Type ${i + 1}`,
            count: (i + 1) * 10,
        }));

        const { container } = render(<ResourceTypeChart data={manyTypes} />);

        // Should render all 20 types
        const colorBoxes = container.querySelectorAll('.h-3.w-3.rounded-sm');
        expect(colorBoxes).toHaveLength(20);
    });

    it('renders PieChart component', () => {
        const { container } = render(<ResourceTypeChart data={mockData} />);

        // Check that the chart structure is rendered
        const chartContainer = container.querySelector('.recharts-wrapper');
        expect(chartContainer).toBeInTheDocument();
    });
});

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorksChart from '@/components/statistics/related-works-chart';

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

describe('RelatedWorksChart', () => {
    const mockData = {
        topDatasets: [
            { id: 1, identifier: '10.5880/test.001', title: 'Dataset with many related works', count: 150 },
            { id: 2, identifier: '10.5880/test.002', title: 'Another important dataset', count: 120 },
            { id: 3, identifier: '10.5880/test.003', title: null, count: 100 },
        ],
        distribution: [
            { range: '1-10', count: 450 },
            { range: '11-25', count: 220 },
            { range: '26-50', count: 100 },
            { range: '51-100', count: 50 },
        ],
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<RelatedWorksChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('displays the distribution title', () => {
        render(<RelatedWorksChart data={mockData} />);

        expect(screen.getByText('Distribution by Range')).toBeInTheDocument();
    });

    it('renders recharts BarChart wrapper', () => {
        const { container } = render(<RelatedWorksChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('displays the top datasets table', () => {
        render(<RelatedWorksChart data={mockData} />);

        expect(screen.getByText('Top 20 Datasets with Most Related Works')).toBeInTheDocument();
    });

    it('renders dataset identifiers in the table', () => {
        render(<RelatedWorksChart data={mockData} />);

        expect(screen.getByText('10.5880/test.001')).toBeInTheDocument();
        expect(screen.getByText('10.5880/test.002')).toBeInTheDocument();
    });

    it('renders dataset titles in the table', () => {
        render(<RelatedWorksChart data={mockData} />);

        expect(screen.getByText('Dataset with many related works')).toBeInTheDocument();
        expect(screen.getByText('Another important dataset')).toBeInTheDocument();
    });

    it('handles null titles with dash', () => {
        render(<RelatedWorksChart data={mockData} />);

        // The dataset without a title should show '-'
        const cells = screen.getAllByRole('cell');
        expect(cells.some((cell) => cell.textContent === '-')).toBe(true);
    });

    it('renders related works counts', () => {
        render(<RelatedWorksChart data={mockData} />);

        expect(screen.getByText('150')).toBeInTheDocument();
        expect(screen.getByText('120')).toBeInTheDocument();
        expect(screen.getByText('100')).toBeInTheDocument();
    });

    it('renders table headers', () => {
        render(<RelatedWorksChart data={mockData} />);

        expect(screen.getByText('Rank')).toBeInTheDocument();
        expect(screen.getByText('Identifier')).toBeInTheDocument();
        expect(screen.getByText('Title')).toBeInTheDocument();
        expect(screen.getByText('Related Works')).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        const emptyData = {
            topDatasets: [],
            distribution: [],
        };
        render(<RelatedWorksChart data={emptyData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('truncates long titles', () => {
        const longTitleData = {
            ...mockData,
            topDatasets: [
                {
                    id: 1,
                    identifier: '10.5880/test.001',
                    title: 'This is a very long title that exceeds the maximum character limit and should be truncated',
                    count: 150,
                },
            ],
        };
        const { container } = render(<RelatedWorksChart data={longTitleData} />);

        // Check that text ends with '...'
        expect(container.textContent).toContain('...');
    });
});

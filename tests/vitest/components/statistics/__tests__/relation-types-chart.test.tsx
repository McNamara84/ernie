import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelationTypesChart from '@/components/statistics/relation-types-chart';

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

describe('RelationTypesChart', () => {
    const mockData = [
        { type: 'IsSupplementTo', count: 450, datasetCount: 350, percentage: 45.0 },
        { type: 'References', count: 220, datasetCount: 180, percentage: 22.0 },
        { type: 'IsReferencedBy', count: 150, datasetCount: 120, percentage: 15.0 },
        { type: 'Cites', count: 100, datasetCount: 80, percentage: 10.0 },
        { type: 'IsCitedBy', count: 80, datasetCount: 60, percentage: 8.0 },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<RelationTypesChart data={mockData} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('displays the chart title with default limit', () => {
        render(<RelationTypesChart data={mockData} />);

        expect(screen.getByText('Top 15 Relation Types by Occurrences')).toBeInTheDocument();
    });

    it('displays the chart title with custom limit', () => {
        render(<RelationTypesChart data={mockData} limit={10} />);

        expect(screen.getByText('Top 10 Relation Types by Occurrences')).toBeInTheDocument();
    });

    it('renders recharts BarChart wrapper', () => {
        const { container } = render(<RelationTypesChart data={mockData} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<RelationTypesChart data={[]} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('displays the detailed table', () => {
        render(<RelationTypesChart data={mockData} />);

        expect(screen.getByText('All Relation Types - Detailed View')).toBeInTheDocument();
    });

    it('renders table with relation type names', () => {
        render(<RelationTypesChart data={mockData} />);

        expect(screen.getByText('IsSupplementTo')).toBeInTheDocument();
        expect(screen.getByText('References')).toBeInTheDocument();
        expect(screen.getByText('IsReferencedBy')).toBeInTheDocument();
    });

    it('shows all data in the detailed table regardless of limit', () => {
        const manyTypes = Array.from({ length: 20 }, (_, i) => ({
            type: `RelationType${i + 1}`,
            count: 100 - i * 5,
            datasetCount: 80 - i * 4,
            percentage: 20 - i,
        }));

        render(<RelationTypesChart data={manyTypes} limit={5} />);

        // Chart shows top 5, but detailed table shows all
        expect(screen.getByText('RelationType1')).toBeInTheDocument();
        expect(screen.getByText('RelationType20')).toBeInTheDocument();
    });
});

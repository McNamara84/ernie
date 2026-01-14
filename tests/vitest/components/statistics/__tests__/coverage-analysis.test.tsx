import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import CoverageAnalysis from '@/components/statistics/coverage-analysis';

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

describe('CoverageAnalysis', () => {
    const mockData = {
        withNoRelatedWorks: 100,
        withOnlyIsSupplementTo: 250,
        withMultipleTypes: 150,
        avgTypesPerDataset: 2.5,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the chart container', () => {
        render(<CoverageAnalysis data={mockData} totalDatasets={500} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('displays the chart title', () => {
        render(<CoverageAnalysis data={mockData} totalDatasets={500} />);

        expect(screen.getByText('Related Works Coverage Distribution')).toBeInTheDocument();
    });

    it('renders recharts PieChart wrapper', () => {
        const { container } = render(<CoverageAnalysis data={mockData} totalDatasets={500} />);

        const chartWrapper = container.querySelector('.recharts-wrapper');
        expect(chartWrapper).toBeInTheDocument();
    });

    it('handles zero total datasets gracefully', () => {
        render(<CoverageAnalysis data={mockData} totalDatasets={0} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });

    it('displays summary cards', () => {
        render(<CoverageAnalysis data={mockData} totalDatasets={500} />);

        expect(screen.getByText('No Related Works')).toBeInTheDocument();
        expect(screen.getByText('Only IsSupplementTo')).toBeInTheDocument();
        expect(screen.getByText('Multiple Types')).toBeInTheDocument();
    });

    it('shows datasets with related works in insights', () => {
        render(<CoverageAnalysis data={mockData} totalDatasets={500} />);

        // 500 - 100 = 400 datasets with related works
        expect(screen.getByText('400')).toBeInTheDocument();
    });

    it('shows average types per dataset', () => {
        render(<CoverageAnalysis data={mockData} totalDatasets={500} />);

        expect(screen.getByText('Avg. Types per Dataset')).toBeInTheDocument();
        // The value 2.5 is displayed in multiple places (card and insights)
        expect(screen.getAllByText('2.5').length).toBeGreaterThan(0);
    });

    it('displays legend items', () => {
        render(<CoverageAnalysis data={mockData} totalDatasets={500} />);

        expect(screen.getByText('No Related Works')).toBeInTheDocument();
        expect(screen.getByText('Only IsSupplementTo')).toBeInTheDocument();
        expect(screen.getByText('Multiple Types')).toBeInTheDocument();
    });

    it('handles all zero values', () => {
        const zeroData = {
            withNoRelatedWorks: 0,
            withOnlyIsSupplementTo: 0,
            withMultipleTypes: 0,
            avgTypesPerDataset: 0,
        };
        render(<CoverageAnalysis data={zeroData} totalDatasets={0} />);

        expect(screen.getByTestId('responsive-container')).toBeInTheDocument();
    });
});

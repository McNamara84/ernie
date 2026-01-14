import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import CurrentYearChart from '@/components/statistics/current-year-chart';

describe('CurrentYearChart', () => {
    const mockData = {
        year: 2025,
        total: 1250,
        monthly: [
            { month: 1, count: 100 },
            { month: 2, count: 120 },
            { month: 3, count: 150 },
        ],
    };

    it('renders the total publications count', () => {
        render(<CurrentYearChart data={mockData} />);

        // German locale formats 1250 as "1.250"
        expect(screen.getByText('1.250')).toBeInTheDocument();
    });

    it('displays the current year in the description', () => {
        render(<CurrentYearChart data={mockData} />);

        expect(screen.getByText('Total publications in 2025')).toBeInTheDocument();
    });

    it('shows the monthly breakdown notice', () => {
        render(<CurrentYearChart data={mockData} />);

        expect(
            screen.getByText('(Monthly breakdown not available - only publication year is stored)'),
        ).toBeInTheDocument();
    });

    it('handles zero publications', () => {
        const zeroData = {
            year: 2025,
            total: 0,
            monthly: [],
        };
        render(<CurrentYearChart data={zeroData} />);

        expect(screen.getByText('0')).toBeInTheDocument();
    });

    it('handles large publication counts', () => {
        const largeData = {
            year: 2025,
            total: 1500000,
            monthly: [],
        };
        render(<CurrentYearChart data={largeData} />);

        // German locale formats 1500000 as "1.500.000"
        expect(screen.getByText('1.500.000')).toBeInTheDocument();
    });

    it('displays different years correctly', () => {
        const pastYearData = {
            year: 2020,
            total: 500,
            monthly: [],
        };
        render(<CurrentYearChart data={pastYearData} />);

        expect(screen.getByText('Total publications in 2020')).toBeInTheDocument();
    });
});

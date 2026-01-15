import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import IdentifierStatsCard from '@/components/statistics/identifier-stats-card';

describe('IdentifierStatsCard', () => {
    const defaultData = {
        ror: {
            count: 250,
            total: 500,
            percentage: 50,
        },
        orcid: {
            count: 300,
            total: 400,
            percentage: 75,
        },
    };

    describe('ROR statistics', () => {
        it('renders ROR section heading', () => {
            render(<IdentifierStatsCard data={defaultData} />);

            expect(screen.getByText('ROR-IDs in Affiliations')).toBeInTheDocument();
        });

        it('displays ROR count and total', () => {
            render(<IdentifierStatsCard data={defaultData} />);

            expect(screen.getByText(/250 of 500 affiliations have ROR identifiers/)).toBeInTheDocument();
        });

        it('displays ROR percentage', () => {
            render(<IdentifierStatsCard data={defaultData} />);

            expect(screen.getByText('50%')).toBeInTheDocument();
        });

        it('renders ROR progress bar with correct width', () => {
            const { container } = render(<IdentifierStatsCard data={defaultData} />);

            const progressBars = container.querySelectorAll('.h-full.transition-all');
            expect(progressBars[0]).toHaveStyle({ width: '50%' });
        });
    });

    describe('ORCID statistics', () => {
        it('renders ORCID section heading', () => {
            render(<IdentifierStatsCard data={defaultData} />);

            expect(screen.getByText('ORCIDs in Authors/Contributors')).toBeInTheDocument();
        });

        it('displays ORCID count and total', () => {
            render(<IdentifierStatsCard data={defaultData} />);

            expect(screen.getByText(/300 of 400 authors\/contributors have ORCID identifiers/)).toBeInTheDocument();
        });

        it('displays ORCID percentage', () => {
            render(<IdentifierStatsCard data={defaultData} />);

            expect(screen.getByText('75%')).toBeInTheDocument();
        });

        it('renders ORCID progress bar with correct width', () => {
            const { container } = render(<IdentifierStatsCard data={defaultData} />);

            const progressBars = container.querySelectorAll('.h-full.transition-all');
            expect(progressBars[1]).toHaveStyle({ width: '75%' });
        });
    });

    describe('edge cases', () => {
        it('handles zero percentages', () => {
            const zeroData = {
                ror: { count: 0, total: 100, percentage: 0 },
                orcid: { count: 0, total: 50, percentage: 0 },
            };

            render(<IdentifierStatsCard data={zeroData} />);

            const zeroPercentages = screen.getAllByText('0%');
            expect(zeroPercentages).toHaveLength(2);
        });

        it('handles 100% coverage', () => {
            const fullData = {
                ror: { count: 100, total: 100, percentage: 100 },
                orcid: { count: 50, total: 50, percentage: 100 },
            };

            render(<IdentifierStatsCard data={fullData} />);

            const fullPercentages = screen.getAllByText('100%');
            expect(fullPercentages).toHaveLength(2);
        });

        it('formats large numbers with locale', () => {
            const largeData = {
                ror: { count: 1500, total: 3000, percentage: 50 },
                orcid: { count: 2500, total: 5000, percentage: 50 },
            };

            render(<IdentifierStatsCard data={largeData} />);

            // Numbers should be formatted with thousands separator - locale may use '.' or ','
            expect(screen.getByText(/1[.,]500/)).toBeInTheDocument();
            expect(screen.getByText(/3[.,]000/)).toBeInTheDocument();
            expect(screen.getByText(/2[.,]500/)).toBeInTheDocument();
            expect(screen.getByText(/5[.,]000/)).toBeInTheDocument();
        });
    });
});

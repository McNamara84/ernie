import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import AffiliationStatsCard from '@/components/statistics/affiliation-stats-card';

describe('AffiliationStatsCard', () => {
    const defaultData = {
        max_per_agent: 5,
        avg_per_agent: 2.3,
    };

    it('renders the maximum affiliations value', () => {
        render(<AffiliationStatsCard data={defaultData} />);

        expect(screen.getByText('5')).toBeInTheDocument();
        expect(screen.getByText('Maximum Affiliations')).toBeInTheDocument();
    });

    it('renders the average affiliations value', () => {
        render(<AffiliationStatsCard data={defaultData} />);

        expect(screen.getByText('2.3')).toBeInTheDocument();
        expect(screen.getByText('Average Affiliations')).toBeInTheDocument();
    });

    it('renders descriptions for both metrics', () => {
        render(<AffiliationStatsCard data={defaultData} />);

        expect(screen.getByText('Highest number of affiliations per author/contributor')).toBeInTheDocument();
        expect(screen.getByText('Average affiliations per author/contributor')).toBeInTheDocument();
    });

    it('displays zero values correctly', () => {
        const zeroData = {
            max_per_agent: 0,
            avg_per_agent: 0,
        };

        render(<AffiliationStatsCard data={zeroData} />);

        const zeros = screen.getAllByText('0');
        expect(zeros).toHaveLength(2);
    });

    it('renders large numbers correctly', () => {
        const largeData = {
            max_per_agent: 42,
            avg_per_agent: 15.7,
        };

        render(<AffiliationStatsCard data={largeData} />);

        expect(screen.getByText('42')).toBeInTheDocument();
        expect(screen.getByText('15.7')).toBeInTheDocument();
    });
});

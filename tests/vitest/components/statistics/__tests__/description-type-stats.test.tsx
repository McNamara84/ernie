import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import DescriptionTypeStats from '@/components/statistics/description-type-stats';

describe('DescriptionTypeStats', () => {
    const defaultData = [
        { type_id: 'Abstract', count: 150 },
        { type_id: 'Methods', count: 75 },
        { type_id: 'TechnicalInfo', count: 25 },
    ];

    it('renders table headers', () => {
        render(<DescriptionTypeStats data={defaultData} />);

        expect(screen.getByRole('columnheader', { name: /description type/i })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: /count/i })).toBeInTheDocument();
    });

    it('displays all description types', () => {
        render(<DescriptionTypeStats data={defaultData} />);

        expect(screen.getByText('Abstract')).toBeInTheDocument();
        expect(screen.getByText('Methods')).toBeInTheDocument();
        expect(screen.getByText('TechnicalInfo')).toBeInTheDocument();
    });

    it('displays counts for each type', () => {
        render(<DescriptionTypeStats data={defaultData} />);

        expect(screen.getByText('150')).toBeInTheDocument();
        expect(screen.getByText('75')).toBeInTheDocument();
        expect(screen.getByText('25')).toBeInTheDocument();
    });

    it('renders the correct number of rows', () => {
        render(<DescriptionTypeStats data={defaultData} />);

        const rows = screen.getAllByRole('row');
        // 1 header row + 3 data rows
        expect(rows).toHaveLength(4);
    });

    it('handles empty data array', () => {
        render(<DescriptionTypeStats data={[]} />);

        // Should still render table with headers
        expect(screen.getByRole('columnheader', { name: /description type/i })).toBeInTheDocument();
        // Only header row should exist
        const rows = screen.getAllByRole('row');
        expect(rows).toHaveLength(1);
    });

    it('formats large numbers with locale', () => {
        const largeData = [{ type_id: 'Abstract', count: 1500 }];

        render(<DescriptionTypeStats data={largeData} />);

        // Locale may use '.' or ',' as thousands separator
        expect(screen.getByText(/1[.,]500/)).toBeInTheDocument();
    });

    it('renders single item correctly', () => {
        const singleData = [{ type_id: 'Methods', count: 42 }];

        render(<DescriptionTypeStats data={singleData} />);

        expect(screen.getByText('Methods')).toBeInTheDocument();
        expect(screen.getByText('42')).toBeInTheDocument();
    });
});

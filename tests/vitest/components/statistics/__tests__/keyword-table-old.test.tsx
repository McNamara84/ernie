import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import KeywordTableOld from '@/components/statistics/keyword-table-old';

describe('KeywordTableOld', () => {
    const mockData = {
        free: [
            { keyword: 'geology', count: 150 },
            { keyword: 'seismology', count: 120 },
            { keyword: 'geophysics', count: 80 },
        ],
        controlled: [
            { keyword: 'EARTH SCIENCE', count: 500 },
            { keyword: 'SOLID EARTH', count: 350 },
        ],
    };

    it('renders the tabs component', () => {
        render(<KeywordTableOld data={mockData} />);

        expect(screen.getByRole('tablist')).toBeInTheDocument();
    });

    it('shows free keywords tab with count by default', () => {
        render(<KeywordTableOld data={mockData} />);

        expect(screen.getByRole('tab', { name: /Free Keywords \(3\)/i })).toBeInTheDocument();
    });

    it('shows controlled keywords tab with count', () => {
        render(<KeywordTableOld data={mockData} />);

        expect(screen.getByRole('tab', { name: /Controlled Keywords \(2\)/i })).toBeInTheDocument();
    });

    it('displays free keywords in the default tab', () => {
        render(<KeywordTableOld data={mockData} />);

        expect(screen.getByText('geology')).toBeInTheDocument();
        expect(screen.getByText('seismology')).toBeInTheDocument();
        expect(screen.getByText('geophysics')).toBeInTheDocument();
    });

    it('displays usage counts for free keywords', () => {
        render(<KeywordTableOld data={mockData} />);

        expect(screen.getByText('150')).toBeInTheDocument();
        expect(screen.getByText('120')).toBeInTheDocument();
        expect(screen.getByText('80')).toBeInTheDocument();
    });

    it('switches to controlled keywords tab when clicked', async () => {
        const user = userEvent.setup();
        render(<KeywordTableOld data={mockData} />);

        await user.click(screen.getByRole('tab', { name: /Controlled Keywords/i }));

        expect(screen.getByText('EARTH SCIENCE')).toBeInTheDocument();
        expect(screen.getByText('SOLID EARTH')).toBeInTheDocument();
    });

    it('displays table headers', () => {
        render(<KeywordTableOld data={mockData} />);

        expect(screen.getByText('#')).toBeInTheDocument();
        expect(screen.getByText('Keyword')).toBeInTheDocument();
        expect(screen.getByText('Usage Count')).toBeInTheDocument();
    });

    it('shows rank numbers for each keyword', () => {
        render(<KeywordTableOld data={mockData} />);

        // First 3 ranks for free keywords
        expect(screen.getByText('1')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        const emptyData = {
            free: [],
            controlled: [],
        };
        render(<KeywordTableOld data={emptyData} />);

        expect(screen.getByRole('tab', { name: /Free Keywords \(0\)/i })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: /Controlled Keywords \(0\)/i })).toBeInTheDocument();
    });

    it('formats large usage counts with locale', () => {
        const largeData = {
            free: [{ keyword: 'popular', count: 15000 }],
            controlled: [],
        };
        render(<KeywordTableOld data={largeData} />);

        // German locale formats as 15.000
        expect(screen.getByText('15.000')).toBeInTheDocument();
    });
});

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import KeywordTable from '@/components/statistics/keyword-table';

describe('KeywordTable', () => {
    const defaultData = {
        free: [
            { keyword: 'climate', count: 50 },
            { keyword: 'earthquake', count: 30 },
        ],
        controlled: [
            { keyword: 'EARTH SCIENCE', count: 100, thesaurus: 'GCMD' },
            { keyword: 'ATMOSPHERE', count: 75, thesaurus: 'GCMD' },
            { keyword: 'SOLID EARTH', count: 60, thesaurus: 'GCMD' },
        ],
    };

    it('renders the card title', () => {
        render(<KeywordTable data={defaultData} />);

        expect(screen.getByText('Top Keywords')).toBeInTheDocument();
    });

    it('renders the controlled keywords count in description', () => {
        render(<KeywordTable data={defaultData} />);

        expect(screen.getByText(/3 shown/)).toBeInTheDocument();
    });

    it('renders table headers', () => {
        render(<KeywordTable data={defaultData} />);

        expect(screen.getByRole('columnheader', { name: '#' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Keyword' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Thesaurus' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Usage Count' })).toBeInTheDocument();
    });

    it('renders controlled keywords in table rows', () => {
        render(<KeywordTable data={defaultData} />);

        expect(screen.getByText('EARTH SCIENCE')).toBeInTheDocument();
        expect(screen.getByText('ATMOSPHERE')).toBeInTheDocument();
        expect(screen.getByText('SOLID EARTH')).toBeInTheDocument();
    });

    it('renders thesaurus as badge', () => {
        render(<KeywordTable data={defaultData} />);

        const gcmdBadges = screen.getAllByText('GCMD');
        expect(gcmdBadges.length).toBeGreaterThan(0);
    });

    it('renders usage counts formatted with locale', () => {
        const dataWithLargeCounts = {
            free: [],
            controlled: [{ keyword: 'TEST', count: 1500, thesaurus: 'Test' }],
        };
        render(<KeywordTable data={dataWithLargeCounts} />);

        // toLocaleString formats 1500 as "1.500" in German locale
        expect(screen.getByText(/1\.500|1,500/)).toBeInTheDocument();
    });

    it('handles empty controlled keywords array', () => {
        const emptyData = {
            free: [],
            controlled: [],
        };
        render(<KeywordTable data={emptyData} />);

        expect(screen.getByText(/0 shown/)).toBeInTheDocument();
    });

    it('renders keyword without thesaurus correctly', () => {
        const dataWithoutThesaurus = {
            free: [],
            controlled: [{ keyword: 'No Thesaurus Keyword', count: 10 }],
        };
        render(<KeywordTable data={dataWithoutThesaurus} />);

        expect(screen.getByText('No Thesaurus Keyword')).toBeInTheDocument();
    });

    it('displays row numbers correctly', () => {
        render(<KeywordTable data={defaultData} />);

        expect(screen.getByText('1')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
        expect(screen.getByText('3')).toBeInTheDocument();
    });
});

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TopDatasetsByRelationType from '@/components/statistics/top-datasets-by-relation-type';

describe('TopDatasetsByRelationType', () => {
    const mockData = {
        Cites: [
            { id: 1, identifier: '10.5880/cites.001', title: 'Dataset that cites many works', count: 50 },
            { id: 2, identifier: '10.5880/cites.002', title: 'Another citing dataset', count: 40 },
        ],
        References: [
            { id: 3, identifier: '10.5880/refs.001', title: null, count: 30 },
        ],
        IsSupplementTo: [],
        IsCitedBy: [
            { id: 4, identifier: '10.5880/cited.001', title: 'Highly cited dataset', count: 100 },
        ],
    };

    it('renders the main heading', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        expect(screen.getByText(/Top 5 Datasets by Relation Type/)).toBeInTheDocument();
    });

    it('renders the description', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        expect(
            screen.getByText(/Datasets with the highest usage of each relation type/),
        ).toBeInTheDocument();
    });

    it('renders cards for relation types with data', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        expect(screen.getByText(/Top 5: Cites/)).toBeInTheDocument();
        expect(screen.getByText(/Top 5: References/)).toBeInTheDocument();
    });

    it('renders dataset identifiers in tables', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        expect(screen.getByText('10.5880/cites.001')).toBeInTheDocument();
        expect(screen.getByText('10.5880/cites.002')).toBeInTheDocument();
    });

    it('renders dataset counts', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        expect(screen.getByText('50')).toBeInTheDocument();
        expect(screen.getByText('40')).toBeInTheDocument();
    });

    it('shows "No datasets found" for empty relation types', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        // Multiple empty relation types will show this message
        const messages = screen.getAllByText('No datasets found with this relation type.');
        expect(messages.length).toBeGreaterThan(0);
    });

    it('renders table headers', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        // Multiple tables have these headers
        expect(screen.getAllByText('#').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Identifier').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Count').length).toBeGreaterThan(0);
    });

    it('renders dataset titles when available', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        expect(screen.getByText('Dataset that cites many works')).toBeInTheDocument();
        expect(screen.getByText('Another citing dataset')).toBeInTheDocument();
    });

    it('handles empty data gracefully', () => {
        render(<TopDatasetsByRelationType data={{}} />);

        // Should still render the heading
        expect(screen.getByText(/Top 5 Datasets by Relation Type/)).toBeInTheDocument();
    });

    it('renders all ordered relation types', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        // Check some of the predefined relation types are rendered
        expect(screen.getByText(/Top 5: Cites/)).toBeInTheDocument();
        expect(screen.getByText(/Top 5: IsCitedBy/)).toBeInTheDocument();
        expect(screen.getByText(/Top 5: IsNewVersionOf/)).toBeInTheDocument();
    });

    it('renders emoji icons with proper aria labels', () => {
        render(<TopDatasetsByRelationType data={mockData} />);

        // Medal emoji for the main heading
        expect(screen.getByRole('img', { name: 'Medal' })).toBeInTheDocument();
        
        // Open book emoji for Cites
        expect(screen.getByRole('img', { name: 'Open book' })).toBeInTheDocument();
    });
});

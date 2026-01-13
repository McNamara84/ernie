import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import DescriptionStatsCard from '@/components/statistics/description-stats-card';

describe('DescriptionStatsCard', () => {
    const defaultData = {
        by_type: [
            { type_id: 'Abstract', count: 150 },
            { type_id: 'Methods', count: 75 },
            { type_id: 'TechnicalInfo', count: 25 },
        ],
        longest_abstract: {
            length: 5000,
            preview: 'This is the preview of the longest abstract which contains important information...',
        },
        shortest_abstract: {
            length: 50,
            preview: 'Short abstract text.',
        },
    };

    describe('types tab', () => {
        it('renders the types tab by default', () => {
            render(<DescriptionStatsCard data={defaultData} />);

            expect(screen.getByRole('tab', { name: /by type/i })).toBeInTheDocument();
            expect(screen.getByRole('tab', { name: /abstract analysis/i })).toBeInTheDocument();
        });

        it('displays description types in a table', () => {
            render(<DescriptionStatsCard data={defaultData} />);

            expect(screen.getByRole('columnheader', { name: /description type/i })).toBeInTheDocument();
            expect(screen.getByRole('columnheader', { name: /count/i })).toBeInTheDocument();
        });

        it('shows all description types with counts', () => {
            render(<DescriptionStatsCard data={defaultData} />);

            expect(screen.getByText('Abstract')).toBeInTheDocument();
            expect(screen.getByText('150')).toBeInTheDocument();
            expect(screen.getByText('Methods')).toBeInTheDocument();
            expect(screen.getByText('75')).toBeInTheDocument();
            expect(screen.getByText('TechnicalInfo')).toBeInTheDocument();
            expect(screen.getByText('25')).toBeInTheDocument();
        });

        it('handles empty types array', () => {
            const emptyData = {
                ...defaultData,
                by_type: [],
            };

            render(<DescriptionStatsCard data={emptyData} />);

            // Table should still render with headers
            expect(screen.getByRole('columnheader', { name: /description type/i })).toBeInTheDocument();
        });
    });

    describe('abstract analysis tab', () => {
        it('switches to abstract analysis tab on click', async () => {
            const user = userEvent.setup();
            render(<DescriptionStatsCard data={defaultData} />);

            await user.click(screen.getByRole('tab', { name: /abstract analysis/i }));

            expect(screen.getByText('Longest Abstract')).toBeInTheDocument();
            expect(screen.getByText('Shortest Abstract')).toBeInTheDocument();
        });

        it('displays longest abstract info', async () => {
            const user = userEvent.setup();
            render(<DescriptionStatsCard data={defaultData} />);

            await user.click(screen.getByRole('tab', { name: /abstract analysis/i }));

            // Check for character count - locale may use '.' or ',' as separator
            expect(screen.getByText(/5[.,]000/)).toBeInTheDocument();
            expect(screen.getByText(/This is the preview of the longest abstract/)).toBeInTheDocument();
        });

        it('displays shortest abstract info', async () => {
            const user = userEvent.setup();
            render(<DescriptionStatsCard data={defaultData} />);

            await user.click(screen.getByRole('tab', { name: /abstract analysis/i }));

            expect(screen.getByText('50 characters')).toBeInTheDocument();
            expect(screen.getByText('Short abstract text.')).toBeInTheDocument();
        });

        it('adds ellipsis for long abstracts over 200 characters', async () => {
            const user = userEvent.setup();
            const longAbstractData = {
                ...defaultData,
                longest_abstract: {
                    length: 250,
                    preview: 'A'.repeat(200),
                },
            };

            render(<DescriptionStatsCard data={longAbstractData} />);

            await user.click(screen.getByRole('tab', { name: /abstract analysis/i }));

            const previewElement = screen.getByText(/A+\.\.\./);
            expect(previewElement).toBeInTheDocument();
        });

        it('handles null longest_abstract', async () => {
            const user = userEvent.setup();
            const nullData = {
                ...defaultData,
                longest_abstract: null,
            };

            render(<DescriptionStatsCard data={nullData} />);

            await user.click(screen.getByRole('tab', { name: /abstract analysis/i }));

            expect(screen.queryByText('Longest Abstract')).not.toBeInTheDocument();
            expect(screen.getByText('Shortest Abstract')).toBeInTheDocument();
        });

        it('handles null shortest_abstract', async () => {
            const user = userEvent.setup();
            const nullData = {
                ...defaultData,
                shortest_abstract: null,
            };

            render(<DescriptionStatsCard data={nullData} />);

            await user.click(screen.getByRole('tab', { name: /abstract analysis/i }));

            expect(screen.getByText('Longest Abstract')).toBeInTheDocument();
            expect(screen.queryByText('Shortest Abstract')).not.toBeInTheDocument();
        });

        it('handles both abstracts being null', async () => {
            const user = userEvent.setup();
            const nullData = {
                ...defaultData,
                longest_abstract: null,
                shortest_abstract: null,
            };

            render(<DescriptionStatsCard data={nullData} />);

            await user.click(screen.getByRole('tab', { name: /abstract analysis/i }));

            expect(screen.queryByText('Longest Abstract')).not.toBeInTheDocument();
            expect(screen.queryByText('Shortest Abstract')).not.toBeInTheDocument();
        });
    });
});

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { IgsnDrillingSection } from '@/pages/LandingPages/components/IgsnDrillingSection';
import type {
    LandingPageContributor,
    LandingPageFundingReference,
    LandingPageIgsnMetadata,
    LandingPageResourceDate,
} from '@/types/landing-page';

const renderSection = (
    igsn: LandingPageIgsnMetadata | null,
    contributors: LandingPageContributor[] = [],
    fundingReferences: LandingPageFundingReference[] = [],
    dates: LandingPageResourceDate[] = [],
) => render(<IgsnDrillingSection igsn={igsn} contributors={contributors} fundingReferences={fundingReferences} dates={dates} />);

describe('IgsnDrillingSection', () => {
    it('renders nothing without drilling metadata', () => {
        const { container } = renderSection(null);

        expect(container.firstChild).toBeNull();
    });

    it('renders the complete ICDP drilling metadata in acceptance-criteria order', () => {
        const igsn = {
            collection_method: ' Coring>RockCorer ',
            collection_method_description: 'wireline diamond coring',
            total_lengths: [
                { numeric_value: '2400.1000', unit: 'm' },
                { numeric_value: '12', unit: null },
                { numeric_value: '2400.1000', unit: 'm' },
            ],
            comments: ['First line', ' first line ', 'Second line'],
            platform_type: 'drill rig',
            platform_name: 'Atlas Copco CT20C',
            platform_description: 'slimhole wireline coring system',
            operators: ['Lund University', 'lund university', 'Larsson Drilling'],
            collection_date_precision: 'day',
        } as LandingPageIgsnMetadata;
        const contributors = [
            {
                id: 1,
                position: 1,
                affiliations: [],
                contributor_types: ['Data Collector'],
                contributorable: {
                    type: 'Person',
                    id: 1,
                    given_name: 'Chris',
                    family_name: 'Juhlin',
                    name: null,
                    name_identifier: null,
                    name_identifier_scheme: null,
                },
            },
        ] as LandingPageContributor[];
        const fundingReferences = [
            { id: 1, funder_name: 'Swedish Research Council' },
            { id: 2, funder_name: 'swedish research council' },
        ] as LandingPageFundingReference[];
        const dates = [
            {
                id: 1,
                date_type: 'Collected',
                date_type_slug: 'Collected',
                date_value: '2013-09-10',
                start_date: null,
                end_date: null,
                date_information: 'Legacy IGSN sampling date',
            },
            {
                id: 2,
                date_type: 'Collected',
                date_type_slug: 'Collected',
                date_value: null,
                start_date: '2013-09-05',
                end_date: '2014-08-26',
                date_information: 'Legacy IGSN collection period',
            },
        ];

        const { container } = renderSection(igsn, contributors, fundingReferences, dates);

        expect(screen.getByRole('heading', { name: 'Drilling' })).toBeInTheDocument();
        expect(Array.from(container.querySelectorAll('dt')).map((label) => label.textContent)).toEqual([
            'Collection Method',
            'Collection Method Description',
            'Total Length',
            'Comments',
            'Platform Type',
            'Platform Name',
            'Platform Description',
            'Operator',
            'Funding Agency',
            'Chief Scientist',
            'Sampling Date',
            'Start Date',
            'End Date',
        ]);
        expect(screen.getByText('Collection Method').nextElementSibling).toHaveTextContent('Coring>RockCorer');
        expect(screen.getByText('Total Length').nextElementSibling).toHaveTextContent('2400.1 m; 12');
        expect(screen.getByText('Comments').nextElementSibling).toHaveTextContent('First line; Second line');
        expect(screen.getByText('Operator').nextElementSibling).toHaveTextContent('Lund University; Larsson Drilling');
        expect(screen.getByText('Funding Agency').nextElementSibling).toHaveTextContent('Swedish Research Council');
        expect(screen.getByText('Chief Scientist').nextElementSibling).toHaveTextContent('Chris Juhlin');
        expect(screen.getByText('Sampling Date').nextElementSibling).toHaveTextContent('2013-09-10');
        expect(screen.getByText('Start Date').nextElementSibling).toHaveTextContent('2013-09-05');
        expect(screen.getByText('End Date').nextElementSibling).toHaveTextContent('2014-08-26');
        expect(screen.queryByText('Collection Date Precision')).not.toBeInTheDocument();
        expect(screen.queryByText('Age Range')).not.toBeInTheDocument();
        expect(screen.queryByText('Launch Platform')).not.toBeInTheDocument();
    });

    it('omits whitespace and N/A values without rendering placeholder rows', () => {
        const igsn = {
            collection_method: 'N/A',
            collection_method_description: '   ',
            total_lengths: [{ numeric_value: 'N/A', unit: 'm' }],
            comments: [' n/a '],
            platform_type: 'N/A',
            platform_name: null,
            platform_description: ' ',
            operators: ['N/A'],
        } as LandingPageIgsnMetadata;
        const fundingReferences = [{ id: 1, funder_name: 'N/A' }] as LandingPageFundingReference[];

        const { container } = renderSection(igsn, [], fundingReferences);

        expect(container.firstChild).toBeNull();
        expect(screen.queryByText(/N\/A/i)).not.toBeInTheDocument();
    });

    it('uses a collected date_value for both missing range boundaries', () => {
        const dates: LandingPageResourceDate[] = [
            {
                id: 1,
                date_type: 'Collected',
                date_type_slug: 'Collected',
                date_value: '2024-07',
                start_date: null,
                end_date: null,
                date_information: null,
            },
        ];

        renderSection(null, [], [], dates);

        expect(screen.getByText('Start Date').nextElementSibling).toHaveTextContent('2024-07');
        expect(screen.getByText('End Date').nextElementSibling).toHaveTextContent('2024-07');
    });
});

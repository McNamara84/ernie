import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { IgsnDrillingSection } from '@/pages/LandingPages/components/IgsnDrillingSection';
import type { LandingPageIgsnMetadata } from '@/types/landing-page';

describe('IgsnDrillingSection', () => {
    it('renders nothing without drilling metadata', () => {
        const { container } = render(<IgsnDrillingSection igsn={null} />);

        expect(container.firstChild).toBeNull();
    });

    it('renders all repeated measurements and launch metadata with their units', () => {
        const igsn = {
            total_lengths: [
                { numeric_value: '2400.1000', unit: 'm' },
                { numeric_value: '12', unit: null },
            ],
            age_ranges: [
                { start: '10.000', end: '20.0', unit: 'Ma', end_unit: 'Ma' },
                { start: '5', end: '5000', unit: 'ka', end_unit: 'a' },
            ],
            elevation_ranges: [{ start: '-10', end: '25', unit: 'm', end_unit: 'ft' }],
            launch_platform_names: ['SO-273', ' SO-273 ', 'Meteor'],
            launch_type_names: ['Piston corer'],
            navigation_types: ['GPS', 'DVL'],
        } as LandingPageIgsnMetadata;

        render(<IgsnDrillingSection igsn={igsn} />);

        expect(screen.getByRole('heading', { name: 'Drilling' })).toBeInTheDocument();
        expect(screen.getByText('Total Length').nextElementSibling).toHaveTextContent('2400.1 m; 12');
        expect(screen.getByText('Age Range').nextElementSibling).toHaveTextContent('10 – 20 Ma; 5 ka – 5000 a');
        expect(screen.getByText('Elevation Range').nextElementSibling).toHaveTextContent('-10 m – 25 ft');
        expect(screen.getByText('Launch Platform').nextElementSibling).toHaveTextContent('SO-273; Meteor');
        expect(screen.getByText('Launch Type').nextElementSibling).toHaveTextContent('Piston corer');
        expect(screen.getByText('Navigation Type').nextElementSibling).toHaveTextContent('GPS; DVL');
    });
});

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { DatesSection } from '@/pages/LandingPages/components/DatesSection';
import type { LandingPageResourceDate } from '@/types/landing-page';

const makeDate = (overrides: Partial<LandingPageResourceDate> = {}): LandingPageResourceDate => ({
    id: 1,
    date_type: 'Available',
    date_type_slug: 'available',
    date_value: null,
    start_date: null,
    end_date: null,
    date_information: null,
    ...overrides,
});

describe('DatesSection', () => {
    it('returns null when no displayable dates are present', () => {
        const { container } = render(
            <DatesSection
                dates={[
                    makeDate({ date_type: 'Coverage', date_type_slug: 'coverage', date_value: '2024-01-01' }),
                    makeDate({ id: 2, date_type: 'Available', date_type_slug: 'available' }),
                ]}
            />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('renders single dates and periods while omitting coverage', () => {
        render(
            <DatesSection
                dates={[
                    makeDate({ id: 1, date_type: 'Available', date_value: '2024-01-15' }),
                    makeDate({ id: 2, date_type: 'Collected', start_date: '2023-06-01', end_date: '2023-06-30' }),
                    makeDate({ id: 3, date_type: 'Coverage', date_type_slug: 'coverage', start_date: '2022-01-01', end_date: '2022-12-31' }),
                ]}
            />,
        );

        expect(screen.getByText('Dates')).toBeInTheDocument();
        expect(screen.getByText('Available')).toBeInTheDocument();
        expect(screen.getByText('2024-01-15')).toBeInTheDocument();
        expect(screen.getByText('Collected')).toBeInTheDocument();
        expect(screen.getByText('2023-06-01 - 2023-06-30')).toBeInTheDocument();
        expect(screen.queryByText('Coverage')).not.toBeInTheDocument();
        expect(screen.queryByText('2022-01-01 - 2022-12-31')).not.toBeInTheDocument();
    });

    it('shows date information below the date value', () => {
        render(<DatesSection dates={[makeDate({ date_value: '2024-01-15', date_information: 'Embargo lifted' })]} />);

        expect(screen.getByText('2024-01-15')).toBeInTheDocument();
        expect(screen.getByText('Embargo lifted')).toBeInTheDocument();
    });

    it('disambiguates repeated date labels', () => {
        render(
            <DatesSection
                dates={[
                    makeDate({ id: 1, date_type: 'Collected', start_date: '2024-01-01', end_date: '2024-01-02' }),
                    makeDate({ id: 2, date_type: 'Collected', start_date: '2024-02-01', end_date: '2024-02-02' }),
                ]}
            />,
        );

        expect(screen.getByText('Collected')).toBeInTheDocument();
        expect(screen.getByText('Collected 2')).toBeInTheDocument();
    });
});

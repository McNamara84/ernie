import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { GeneralSection } from '@/pages/LandingPages/components/GeneralSection';
import type { LandingPageIgsnMetadata, LandingPageResourceDate } from '@/types/landing-page';

const baseIgsn = (overrides: Partial<LandingPageIgsnMetadata> = {}): LandingPageIgsnMetadata => ({
    igsn: null,
    name: null,
    user_code: null,
    sample_type: null,
    material: null,
    cruise_field_program: null,
    sample_purpose: null,
    collection_method: null,
    collection_method_description: null,
    collection_date_precision: null,
    depth_min: null,
    depth_max: null,
    depth_scale: null,
    coordinate_system: null,
    sample_access: null,
    comments: [],
    current_archive: null,
    current_archive_contact: null,
    original_archive: null,
    original_archive_contact: null,
    sizes: [],
    geological_units: [],
    parent: null,
    ...overrides,
});

describe('GeneralSection', () => {
    it('returns null when nothing has content', () => {
        const { container } = render(<GeneralSection igsn={null} dates={[]} />);
        expect(container.firstChild).toBeNull();
    });

    it('returns null when igsn is undefined and doi is empty string', () => {
        const { container } = render(<GeneralSection igsn={undefined} dates={[]} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders parent IGSN as plain text when no landing page is published', () => {
        const igsn = baseIgsn({
            parent: { igsn: 'IGSN-PARENT', doi: '10.60510/igsn-parent', landing_page: null },
        });
        render(<GeneralSection igsn={igsn} dates={[]} />);

        const text = screen.getByText('IGSN-PARENT');
        expect(text.tagName).not.toBe('A');
    });

    it('renders parent IGSN as link when landing page is present', () => {
        const igsn = baseIgsn({
            parent: { igsn: 'IGSN-PARENT', doi: '10.60510/igsn-parent', landing_page: { public_url: 'https://example.test/p' } },
        });
        render(<GeneralSection igsn={igsn} dates={[]} />);

        const link = screen.getByRole('link', { name: 'IGSN-PARENT' });
        expect(link).toHaveAttribute('href', 'https://example.test/p');
    });

    it('hides parent IGSN row when there is no parent', () => {
        const igsn = baseIgsn({ parent: null, sample_type: 'Rock' });
        render(<GeneralSection igsn={igsn} dates={[]} />);

        expect(screen.queryByText('Parent IGSN')).not.toBeInTheDocument();
        // sentinel
        expect(screen.getByText('Type')).toBeInTheDocument();
    });

    it('renders Project, Name and IGSN from the explicit IGSN contract', () => {
        render(<GeneralSection igsn={baseIgsn({ user_code: 'Resalt', name: 'ODG_1B_1', igsn: 'GFLMU0020' })} dates={[]} />);

        expect(screen.getByText('Resalt')).toBeInTheDocument();
        expect(screen.getByText('ODG_1B_1')).toBeInTheDocument();
        expect(screen.getByText('GFLMU0020')).toBeInTheDocument();
        expect(screen.queryByText(/10\.60510/i)).not.toBeInTheDocument();
    });

    it('uses the Available date for the Release Date row', () => {
        const dates: LandingPageResourceDate[] = [
            {
                id: 1,
                date_type: 'Collected',
                date_type_slug: 'Collected',
                date_value: '2023-01-01',
                start_date: null,
                end_date: null,
                date_information: null,
            },
            {
                id: 2,
                date_type: 'Available',
                date_type_slug: 'Available',
                date_value: '2024-05-10',
                start_date: null,
                end_date: null,
                date_information: null,
            },
        ];

        render(<GeneralSection igsn={null} dates={dates} />);

        expect(screen.getByText('Release Date')).toBeInTheDocument();
        expect(screen.getByText('2024-05-10')).toBeInTheDocument();
    });

    it('hides Purpose when sample_purpose is whitespace-only', () => {
        const igsn = baseIgsn({ sample_purpose: '   ' });

        const { container } = render(<GeneralSection igsn={igsn} dates={[]} />);

        // Card collapses entirely since no other fields are set
        expect(container.firstChild).toBeNull();
    });
});

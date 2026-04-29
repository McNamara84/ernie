import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { LandingPageFundingReference, LandingPageIgsnMetadata, LandingPageResourceDate } from '@/types/landing-page';

import { GeneralSection } from '@/pages/LandingPages/components/GeneralSection';

const baseIgsn = (overrides: Partial<LandingPageIgsnMetadata> = {}): LandingPageIgsnMetadata => ({
    sample_type: null,
    material: null,
    cruise_field_program: null,
    sample_purpose: null,
    collection_method: null,
    collection_method_description: null,
    parent: null,
    ...overrides,
});

describe('GeneralSection', () => {
    it('returns null when nothing has content', () => {
        const { container } = render(
            <GeneralSection igsn={null} doi={null} fundingReferences={[]} dates={[]} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('returns null when igsn is undefined and doi is empty string', () => {
        const { container } = render(
            <GeneralSection igsn={undefined} doi="" fundingReferences={[]} dates={[]} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders parent IGSN as plain text when no landing page is published', () => {
        const igsn = baseIgsn({
            parent: { doi: '10.58050/IGSN-PARENT', landing_page: null },
        });
        render(<GeneralSection igsn={igsn} doi={null} fundingReferences={[]} dates={[]} />);

        const text = screen.getByText('10.58050/IGSN-PARENT');
        expect(text.tagName).not.toBe('A');
    });

    it('renders parent IGSN as link when landing page is present', () => {
        const igsn = baseIgsn({
            parent: { doi: '10.58050/IGSN-PARENT', landing_page: { public_url: 'https://example.test/p' } },
        });
        render(<GeneralSection igsn={igsn} doi={null} fundingReferences={[]} dates={[]} />);

        const link = screen.getByRole('link', { name: '10.58050/IGSN-PARENT' });
        expect(link).toHaveAttribute('href', 'https://example.test/p');
    });

    it('hides parent IGSN row when parent has no doi', () => {
        const igsn = baseIgsn({ parent: { doi: null, landing_page: null }, sample_type: 'Rock' });
        render(<GeneralSection igsn={igsn} doi={null} fundingReferences={[]} dates={[]} />);

        expect(screen.queryByText('Parent IGSN')).not.toBeInTheDocument();
        // sentinel
        expect(screen.getByText('Type')).toBeInTheDocument();
    });

    it('deduplicates project award titles and ignores empty/whitespace values', () => {
        const fundingReferences: LandingPageFundingReference[] = [
            { id: 1, funder_name: 'A', award_title: 'Project Alpha', award_number: '1' },
            { id: 2, funder_name: 'B', award_title: 'Project Alpha', award_number: '2' },
            { id: 3, funder_name: 'C', award_title: 'Project Beta', award_number: '3' },
            { id: 4, funder_name: 'D', award_title: '   ', award_number: '4' },
            { id: 5, funder_name: 'E', award_title: null, award_number: '5' },
        ];

        render(<GeneralSection igsn={null} doi={null} fundingReferences={fundingReferences} dates={[]} />);

        expect(screen.getByText('Project Alpha, Project Beta')).toBeInTheDocument();
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

        render(<GeneralSection igsn={null} doi={null} fundingReferences={[]} dates={dates} />);

        expect(screen.getByText('Release Date')).toBeInTheDocument();
        expect(screen.getByText('2024-05-10')).toBeInTheDocument();
    });
});

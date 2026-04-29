import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type {
    LandingPageContributor,
    LandingPageCreatorable,
    LandingPageDescription,
    LandingPageFundingReference,
    LandingPageIgsnClassification,
    LandingPageIgsnMetadata,
    LandingPageResourceDate,
} from '@/types/landing-page';

import { AcquisitionSection } from '@/pages/LandingPages/components/AcquisitionSection';

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

const personEntity = (given: string, family: string): LandingPageCreatorable => ({
    type: 'Person',
    id: 1,
    given_name: given,
    family_name: family,
    name_identifier: null,
    name_identifier_scheme: null,
    name: null,
});

const institutionEntity = (name: string): LandingPageCreatorable => ({
    type: 'Institution',
    id: 1,
    given_name: null,
    family_name: null,
    name_identifier: null,
    name_identifier_scheme: null,
    name,
});

const makeContributor = (
    contributorable: LandingPageCreatorable,
    contributor_types: string[],
    id = 1,
): LandingPageContributor => ({
    id,
    position: id,
    affiliations: [],
    contributor_types,
    contributorable,
});

const makeDate = (overrides: Partial<LandingPageResourceDate> = {}): LandingPageResourceDate => ({
    id: 1,
    date_type: 'Collected',
    date_type_slug: 'Collected',
    date_value: null,
    start_date: null,
    end_date: null,
    date_information: null,
    ...overrides,
});

describe('AcquisitionSection', () => {
    it('returns null when nothing has content', () => {
        const { container } = render(
            <AcquisitionSection
                igsn={null}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders Material and joins classifications with comma', () => {
        const igsn = baseIgsn({ material: 'Granite' });
        const classifications: LandingPageIgsnClassification[] = [
            { id: 1, value: 'Igneous' },
            { id: 2, value: 'Plutonic' },
            { id: 3, value: '   ' },
        ];

        render(
            <AcquisitionSection
                igsn={igsn}
                classifications={classifications}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText('Granite')).toBeInTheDocument();
        expect(screen.getByText('Igneous, Plutonic')).toBeInTheDocument();
    });

    it('renders Collection Method without description as plain text', () => {
        const igsn = baseIgsn({ collection_method: 'Drilling', collection_method_description: null });

        render(
            <AcquisitionSection
                igsn={igsn}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText('Drilling')).toBeInTheDocument();
    });

    it('renders Collection Method with description as composite block', () => {
        const igsn = baseIgsn({
            collection_method: 'Drilling',
            collection_method_description: '5m core barrel',
        });

        render(
            <AcquisitionSection
                igsn={igsn}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText('Drilling')).toBeInTheDocument();
        expect(screen.getByText('5m core barrel')).toBeInTheDocument();
    });

    it('deduplicates funding agencies and ignores empty values', () => {
        const fundingReferences: LandingPageFundingReference[] = [
            { id: 1, funder_name: 'DFG', award_title: null, award_number: null },
            { id: 2, funder_name: 'DFG', award_title: null, award_number: null },
            { id: 3, funder_name: 'NSF', award_title: null, award_number: null },
            { id: 4, funder_name: '   ', award_title: null, award_number: null },
        ];

        render(
            <AcquisitionSection
                igsn={null}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={fundingReferences}
                dates={[]}
            />,
        );

        expect(screen.getByText('DFG, NSF')).toBeInTheDocument();
    });

    it('only uses descriptions of type "Other" for Comments', () => {
        const descriptions: LandingPageDescription[] = [
            { id: 1, value: 'Abstract here', description_type: 'Abstract' },
            { id: 2, value: 'Field notes', description_type: 'Other' },
        ];

        render(
            <AcquisitionSection
                igsn={null}
                classifications={[]}
                descriptions={descriptions}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText('Field notes')).toBeInTheDocument();
        expect(screen.queryByText('Abstract here')).not.toBeInTheDocument();
    });

    it('matches Chief Scientist by Data Collector and DataCollector (case-insensitive)', () => {
        const contributors: LandingPageContributor[] = [
            makeContributor(personEntity('Jane', 'Doe'), ['Data Collector'], 1),
            makeContributor(personEntity('John', 'Smith'), ['DATACOLLECTOR'], 2),
            makeContributor(personEntity('Other', 'Person'), ['Editor'], 3),
            makeContributor(institutionEntity('AWI'), ['datacollector'], 4),
        ];

        render(
            <AcquisitionSection
                igsn={null}
                classifications={[]}
                descriptions={[]}
                contributors={contributors}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText('Jane Doe, John Smith, AWI')).toBeInTheDocument();
        expect(screen.queryByText(/Other Person/)).not.toBeInTheDocument();
    });

    it('hides End Date when equal to Start Date', () => {
        const dates: LandingPageResourceDate[] = [
            makeDate({ start_date: '2023-06-01', end_date: '2023-06-01' }),
        ];

        render(
            <AcquisitionSection
                igsn={baseIgsn({ material: 'Basalt' })}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={dates}
            />,
        );

        expect(screen.getByText('2023-06-01')).toBeInTheDocument();
        expect(screen.queryByText('End Date')).not.toBeInTheDocument();
    });

    it('falls back to date_value when start_date is missing', () => {
        const dates: LandingPageResourceDate[] = [
            makeDate({ date_value: '2023-06-15' }),
        ];

        render(
            <AcquisitionSection
                igsn={baseIgsn({ material: 'Basalt' })}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={dates}
            />,
        );

        expect(screen.getByText('Start Date')).toBeInTheDocument();
        expect(screen.getByText('2023-06-15')).toBeInTheDocument();
    });

    it('skips contributors with empty composed names', () => {
        const contributors: LandingPageContributor[] = [
            makeContributor(personEntity('   ', '   '), ['Data Collector'], 1),
            makeContributor(institutionEntity('   '), ['Data Collector'], 2),
            makeContributor(personEntity('Real', 'Person'), ['Data Collector'], 3),
        ];

        render(
            <AcquisitionSection
                igsn={null}
                classifications={[]}
                descriptions={[]}
                contributors={contributors}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText('Real Person')).toBeInTheDocument();
    });
});

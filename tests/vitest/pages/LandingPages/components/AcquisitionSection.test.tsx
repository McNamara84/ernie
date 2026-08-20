import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { AcquisitionSection } from '@/pages/LandingPages/components/AcquisitionSection';
import type {
    LandingPageContributor,
    LandingPageCreatorable,
    LandingPageDescription,
    LandingPageFundingReference,
    LandingPageIgsnClassification,
    LandingPageIgsnMetadata,
    LandingPageResourceDate,
} from '@/types/landing-page';

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

const makeContributor = (contributorable: LandingPageCreatorable, contributor_types: string[], id = 1): LandingPageContributor => ({
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
            <AcquisitionSection igsn={null} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />,
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

        render(<AcquisitionSection igsn={igsn} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />);

        expect(screen.getByText('Drilling')).toBeInTheDocument();
    });

    it('renders Collection Method with description as composite block', () => {
        const igsn = baseIgsn({
            collection_method: 'Drilling',
            collection_method_description: '5m core barrel',
        });

        render(<AcquisitionSection igsn={igsn} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />);

        expect(screen.getByText('Drilling')).toBeInTheDocument();
        expect(screen.getByText('5m core barrel')).toBeInTheDocument();
    });

    it('hides Collection Method when value is whitespace-only', () => {
        const igsn = baseIgsn({
            collection_method: '   ',
            collection_method_description: 'irrelevant because method is empty',
        });

        const { container } = render(
            <AcquisitionSection igsn={igsn} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />,
        );

        // Card should not render since the only field (collection method) is whitespace
        expect(container.firstChild).toBeNull();
    });

    it('renders Collection Method as plain text when description is whitespace-only', () => {
        const igsn = baseIgsn({
            collection_method: 'Drilling',
            collection_method_description: '   ',
        });

        render(<AcquisitionSection igsn={igsn} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />);

        // Drilling renders, but no extra description block
        expect(screen.getByText('Drilling')).toBeInTheDocument();
        expect(screen.queryByText('   ')).not.toBeInTheDocument();
    });

    it('hides Comments when description value is whitespace-only', () => {
        const descriptions = [{ id: 1, value: '   ', description_type: 'Other' }];

        const { container } = render(
            <AcquisitionSection igsn={null} classifications={[]} descriptions={descriptions} contributors={[]} fundingReferences={[]} dates={[]} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('deduplicates funding agencies and ignores empty values', () => {
        const fr = (id: number, funder_name: string): LandingPageFundingReference => ({
            id,
            funder_name,
            funder_identifier: null,
            funder_identifier_type: null,
            award_number: null,
            award_uri: null,
            award_title: null,
            position: id,
        });
        const fundingReferences: LandingPageFundingReference[] = [fr(1, 'DFG'), fr(2, 'DFG'), fr(3, 'NSF'), fr(4, '   ')];

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
            <AcquisitionSection igsn={null} classifications={[]} descriptions={descriptions} contributors={[]} fundingReferences={[]} dates={[]} />,
        );

        expect(screen.getByText('Field notes')).toBeInTheDocument();
        expect(screen.queryByText('Abstract here')).not.toBeInTheDocument();
    });

    it('merges and deduplicates legacy comments with all DataCite Other descriptions', () => {
        const descriptions: LandingPageDescription[] = [
            { id: 1, value: 'DataCite note', description_type: 'Other' },
            { id: 2, value: ' legacy note ', description_type: 'other' },
            { id: 3, value: 'Unrelated abstract', description_type: 'Abstract' },
        ];

        render(
            <AcquisitionSection
                igsn={baseIgsn({ comments: ['Legacy note', 'Legacy note'] })}
                classifications={[]}
                descriptions={descriptions}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText('Legacy note; DataCite note')).toBeInTheDocument();
        expect(screen.queryByText('Unrelated abstract')).not.toBeInTheDocument();
    });

    it('matches Chief Scientist by Data Collector and DataCollector (case-insensitive)', () => {
        const contributors: LandingPageContributor[] = [
            makeContributor(personEntity('Jane', 'Doe'), ['Data Collector'], 1),
            makeContributor(personEntity('John', 'Smith'), ['DATACOLLECTOR'], 2),
            makeContributor(personEntity('Other', 'Person'), ['Editor'], 3),
            makeContributor(institutionEntity('AWI'), ['datacollector'], 4),
        ];

        render(
            <AcquisitionSection igsn={null} classifications={[]} descriptions={[]} contributors={contributors} fundingReferences={[]} dates={[]} />,
        );

        expect(screen.getByText('Jane Doe, John Smith, AWI')).toBeInTheDocument();
        expect(screen.queryByText(/Other Person/)).not.toBeInTheDocument();
    });

    it('renders equal collection start and end dates in their explicit rows', () => {
        const dates: LandingPageResourceDate[] = [makeDate({ start_date: '2023-06-01', end_date: '2023-06-01' })];

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
        expect(screen.getByText('End Date')).toBeInTheDocument();
        expect(screen.getAllByText('2023-06-01')).toHaveLength(2);
    });

    it('falls back to date_value when start_date is missing', () => {
        const dates: LandingPageResourceDate[] = [makeDate({ date_value: '2023-06-15' })];

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
        expect(screen.getByText('End Date')).toBeInTheDocument();
        expect(screen.getAllByText('2023-06-15')).toHaveLength(2);
    });

    it('skips contributors with empty composed names', () => {
        const contributors: LandingPageContributor[] = [
            makeContributor(personEntity('   ', '   '), ['Data Collector'], 1),
            makeContributor(institutionEntity('   '), ['Data Collector'], 2),
            makeContributor(personEntity('Real', 'Person'), ['Data Collector'], 3),
        ];

        render(
            <AcquisitionSection igsn={null} classifications={[]} descriptions={[]} contributors={contributors} fundingReferences={[]} dates={[]} />,
        );

        expect(screen.getByText('Real Person')).toBeInTheDocument();
    });

    it('renders the approved GFLMU acquisition details', () => {
        const igsn = baseIgsn({
            material: 'Rock',
            comments: ['Granodiorite'],
            collection_method: 'Coring',
            geological_units: [{ id: 1, value: 'Weschnitz Pluton' }],
            sizes: [
                { id: 1, numeric_value: '50.0000', unit: 'mm', type: 'diameter', label: '50 Diameter [mm]' },
                { id: 2, numeric_value: '100.0000', unit: 'mm', type: 'length', label: '100 Length [mm]' },
            ],
        });

        render(
            <AcquisitionSection
                igsn={igsn}
                classifications={[{ id: 1, value: 'Igneous>Plutonic' }]}
                descriptions={[]}
                contributors={[makeContributor(personEntity('Guido', 'Blöcher'), ['DataCollector'])]}
                fundingReferences={[]}
                dates={[makeDate({ start_date: '2021', end_date: '2021' })]}
            />,
        );

        expect(screen.getByText('Weschnitz Pluton')).toBeInTheDocument();
        expect(screen.getByText('Granodiorite')).toBeInTheDocument();
        expect(screen.getByText('Diameter: 50 mm; Length: 100 mm')).toBeInTheDocument();
        expect(screen.getByText('Guido Blöcher')).toBeInTheDocument();
    });
});

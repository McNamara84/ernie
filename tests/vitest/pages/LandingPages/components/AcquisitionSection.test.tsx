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
    material_descriptions: [],
    depth_min: null,
    depth_max: null,
    depth_scale: null,
    coordinate_system: null,
    sample_access: null,
    description_groups: [],
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

    it('renders material-specific classification but never derives description labels from material', () => {
        const igsn = baseIgsn({
            material: 'Granite',
            description_groups: [{ entries: [{ value: 'Unschemed description', scheme: null }] }],
        });
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
        expect(screen.getByText('Granite Classification')).toBeInTheDocument();
        expect(screen.getByText('Description')).toBeInTheDocument();
        expect(screen.queryByText('Granite Description')).not.toBeInTheDocument();
        expect(screen.getByText('Igneous, Plutonic')).toBeInTheDocument();
    });

    it.each([
        ['Rock', 'rock:bedrock igneous'],
        ['Biology', 'vegetation:leaves/needles'],
        ['Rock', 'Igneous>Felsic'],
        ['Rock', 'rock'],
        ['Rock', 'rock:core stone'],
        ['Rock', 'rock:crump'],
        ['Biology', 'vegetation'],
        ['Biology', 'vegetation:leaf litter'],
        ['Biology', 'vegetation:other'],
        ['Biology', 'vegetation:other plant litter'],
        ['Biology', 'vegetation:whole plant'],
        ['Biology', 'vegetation:blossom'],
        ['Biology', 'vegetation:lichen'],
        ['Biology', 'vegetation:root'],
        ['Rock', 'MYL'],
        ['Rock', 'PROTOMYL'],
        ['Rock', 'QUAT'],
        ['Rock', 'SCH'],
        ['Rock', 'UND'],
        ['Rock', 'VOL'],
        ['Rock', 'cataclastic rocks'],
        ['Rock', 'fault related rocks'],
        ['Rock', 'igneous rocks'],
        ['Rock', 'metamorphic rocks'],
        ['Rock', 'mylonitic rocks'],
        ['Rock', 'protomylonites'],
        ['Rock', 'quaternary deposits, metamorphic rocks'],
        ['Rock', 'sample'],
        ['Rock', 'sedimentary rocks'],
        ['Rock', 'undefined'],
        ['Rock', 'volcanic rocks'],
    ])('renders the imported %s legacy classification verbatim', (material, value) => {
        render(
            <AcquisitionSection
                igsn={baseIgsn({ material })}
                classifications={[{ id: 1, value }]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText(value)).toBeInTheDocument();
        expect(screen.getByText(`${material} Classification`).nextElementSibling).not.toHaveTextContent('N/A');
    });

    it.each(['Rock', 'Mineral', 'Biology', 'Sediment'])('derives only the classification label for %s', (material) => {
        render(
            <AcquisitionSection
                igsn={baseIgsn({ material })}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText(`${material} Classification`)).toBeInTheDocument();
        expect(screen.queryByText(`${material} Description`)).not.toBeInTheDocument();
    });

    it('renders Collection Method without description as plain text', () => {
        const igsn = baseIgsn({ collection_method: 'Drilling', collection_method_description: null });

        render(<AcquisitionSection igsn={igsn} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />);

        expect(screen.getByText('Drilling')).toBeInTheDocument();
    });

    it('renders Collection Method and Collection Method Description as independent standard rows', () => {
        const igsn = baseIgsn({
            collection_method: 'Drilling',
            collection_method_description: '5m core barrel',
        });

        render(<AcquisitionSection igsn={igsn} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />);

        expect(screen.getByText('Drilling')).toBeInTheDocument();
        expect(screen.getByText('5m core barrel')).toBeInTheDocument();

        const methodLabel = screen.getByText('Collection Method');
        const descriptionLabel = screen.getByText('Collection Method Description');
        expect(methodLabel.nextElementSibling).toHaveTextContent('Drilling');
        expect(descriptionLabel.nextElementSibling).toHaveTextContent('5m core barrel');
        expect(descriptionLabel.nextElementSibling?.className).toBe(methodLabel.nextElementSibling?.className);
        expect(descriptionLabel.nextElementSibling).not.toHaveClass('text-xs');
    });

    it('shows an independently populated Collection Method Description when the method is empty', () => {
        const igsn = baseIgsn({
            collection_method: '   ',
            collection_method_description: 'irrelevant because method is empty',
        });

        render(<AcquisitionSection igsn={igsn} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />);

        expect(screen.getByText('Collection Method').nextElementSibling).toHaveTextContent('N/A');
        expect(screen.getByText('Collection Method Description').nextElementSibling).toHaveTextContent('irrelevant because method is empty');
    });

    it('shows N/A for a whitespace-only Collection Method Description', () => {
        const igsn = baseIgsn({
            collection_method: 'Drilling',
            collection_method_description: '   ',
        });

        render(<AcquisitionSection igsn={igsn} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />);

        expect(screen.getByText('Drilling')).toBeInTheDocument();
        expect(screen.getByText('Collection Method Description').nextElementSibling).toHaveTextContent('N/A');
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

    it('does not treat DataCite Other descriptions as IGSN comments', () => {
        const descriptions: LandingPageDescription[] = [
            { id: 1, value: 'Abstract here', description_type: 'Abstract' },
            { id: 2, value: 'Field notes', description_type: 'Other' },
        ];

        const { container } = render(
            <AcquisitionSection igsn={null} classifications={[]} descriptions={descriptions} contributors={[]} fundingReferences={[]} dates={[]} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('deduplicates explicit IGSN comments without merging DataCite descriptions', () => {
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

        expect(screen.getByText('Legacy note')).toBeInTheDocument();
        expect(screen.queryByText('DataCite note')).not.toBeInTheDocument();
        expect(screen.queryByText('Unrelated abstract')).not.toBeInTheDocument();
    });

    it('renders every empty IGSN acquisition field as N/A', () => {
        render(<AcquisitionSection igsn={baseIgsn()} classifications={[]} descriptions={[]} contributors={[]} fundingReferences={[]} dates={[]} />);

        [
            'Material',
            'Material Classification',
            'Rock Type',
            'Classification Comments',
            'Geological Age',
            'Geological Unit',
            'Comments',
            'Minimum Depth',
            'Maximum Depth',
            'Depth Scale',
            'Sizes',
            'Collection Method',
            'Collection Method Description',
            'Platform Type',
            'Platform Name',
            'Platform Description',
            'Operator',
            'Funding Agency',
            'Chief Scientist',
            'Sampling Date',
            'Collection Date Precision',
            'Start Date',
            'End Date',
        ].forEach((label) => {
            expect(screen.getByText(label).nextElementSibling).toHaveTextContent('N/A');
        });
    });

    it('renders the NotApplicable storage value with its public label', () => {
        render(
            <AcquisitionSection
                igsn={baseIgsn({ material: 'NotApplicable' })}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText('Not applicable')).toBeInTheDocument();
        expect(screen.getByText('Not applicable Classification')).toBeInTheDocument();
        expect(screen.queryByText('Not applicable Description')).not.toBeInTheDocument();
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

    it('keeps sampling date and collection period distinct and shows their precision', () => {
        const dates: LandingPageResourceDate[] = [
            makeDate({
                id: 1,
                date_value: '2023-06-15T10:30:00Z',
                date_information: 'Legacy IGSN sampling date',
            }),
            makeDate({
                id: 2,
                start_date: '2023-06-01',
                end_date: '2023-06-30',
                date_information: 'Legacy IGSN collection period',
            }),
        ];

        render(
            <AcquisitionSection
                igsn={baseIgsn({ collection_date_precision: 'day' })}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={dates}
            />,
        );

        expect(screen.getByText('Sampling Date').nextElementSibling).toHaveTextContent('2023-06-15T10:30:00Z');
        expect(screen.getByText('Collection Date Precision').nextElementSibling).toHaveTextContent('day');
        expect(screen.getByText('Start Date').nextElementSibling).toHaveTextContent('2023-06-01');
        expect(screen.getByText('End Date').nextElementSibling).toHaveTextContent('2023-06-30');
    });

    it('renders the imported geological operator and classification details additively', () => {
        render(
            <AcquisitionSection
                igsn={baseIgsn({
                    field_names: ['Greywacke'],
                    classification_comments: ['Reviewed classification'],
                    geological_ages: [{ id: 1, value: 'Jurassic' }],
                    operators: ['GNS', 'Webster Drilling'],
                })}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(screen.getByText('Rock Type').nextElementSibling).toHaveTextContent('Greywacke');
        expect(screen.getByText('Classification Comments').nextElementSibling).toHaveTextContent('Reviewed classification');
        expect(screen.getByText('Geological Age').nextElementSibling).toHaveTextContent('Jurassic');
        expect(screen.getByText('Operator').nextElementSibling).toHaveTextContent('GNS; Webster Drilling');
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
            material_descriptions: ['Granodiorite'],
            comments: [],
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
        expect(screen.getByText('Description').nextElementSibling).toHaveTextContent('Granodiorite');
        expect(screen.queryByText('Rock Description')).not.toBeInTheDocument();
        expect(screen.getByText('Comments').nextElementSibling).toHaveTextContent('N/A');
        expect(screen.getByText('Diameter: 50 mm; Length: 100 mm')).toBeInTheDocument();
        expect(screen.getByText('Guido Blöcher')).toBeInTheDocument();
    });

    it('preserves description groups entry order schemes and semicolons and renders platform fields', () => {
        const { container } = render(
            <AcquisitionSection
                igsn={baseIgsn({
                    material: 'Rock',
                    description_groups: [
                        {
                            entries: [
                                { value: 'Core Oriented? 0; RQD Abundance: 0;', scheme: null },
                                { value: 'Musc-bio schist', scheme: 'Rock Type' },
                            ],
                        },
                        {
                            entries: [
                                { value: 'white', scheme: 'locality_description' },
                                { value: 'Quartzite', scheme: 'Existing Description' },
                            ],
                        },
                    ],
                    platform_type: 'Drill Rig',
                    platform_name: 'MSR Punto',
                    platform_description: 'UDR',
                })}
                classifications={[]}
                descriptions={[]}
                contributors={[]}
                fundingReferences={[]}
                dates={[]}
            />,
        );

        expect(container.querySelectorAll('[data-slot="igsn-description-group"]')).toHaveLength(2);
        expect(screen.getByText('Description').nextElementSibling).toHaveTextContent('Core Oriented? 0; RQD Abundance: 0;');
        expect(screen.getByText('Rock Type Description').nextElementSibling).toHaveTextContent('Musc-bio schist');
        expect(screen.getByText('Locality Description').nextElementSibling).toHaveTextContent('white');
        expect(screen.getByText('Existing Description').nextElementSibling).toHaveTextContent('Quartzite');
        expect(screen.queryByText('Existing Description Description')).not.toBeInTheDocument();
        expect(screen.getByText('Platform Type').nextElementSibling).toHaveTextContent('Drill Rig');
        expect(screen.getByText('Platform Name').nextElementSibling).toHaveTextContent('MSR Punto');
        expect(screen.getByText('Platform Description').nextElementSibling).toHaveTextContent('UDR');
    });
});

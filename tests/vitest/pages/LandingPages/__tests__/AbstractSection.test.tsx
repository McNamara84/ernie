/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { AbstractSection } from '@/pages/LandingPages/components/AbstractSection';

// Mock data factories
const createDescription = (overrides: Partial<{ id: number; value: string; description_type: string | null }> = {}) => ({
    id: 1,
    value: 'This is a test abstract describing the dataset.',
    description_type: 'Abstract',
    ...overrides,
});

const createAffiliation = (overrides: Partial<{ id: number; name: string; affiliation_identifier: string | null; affiliation_identifier_scheme: string | null }> = {}) => ({
    id: 1,
    name: 'GFZ Potsdam',
    affiliation_identifier: null,
    affiliation_identifier_scheme: null,
    ...overrides,
});

const createCreator = (overrides: Partial<{
    id: number;
    position: number;
    affiliations: Array<{ id: number; name: string; affiliation_identifier: string | null; affiliation_identifier_scheme: string | null }>;
    creatorable: Partial<{
        type: string;
        id: number;
        given_name: string | null;
        family_name: string | null;
        name_identifier: string | null;
        name_identifier_scheme: string | null;
        name: string | null;
    }>;
}> = {}) => ({
    id: overrides.id ?? 1,
    position: overrides.position ?? 1,
    affiliations: overrides.affiliations ?? [],
    creatorable: {
        type: 'Person',
        id: 1,
        given_name: 'John' as string | null,
        family_name: 'Doe' as string | null,
        name_identifier: null as string | null,
        name_identifier_scheme: null as string | null,
        name: null as string | null,
        ...overrides.creatorable,
    },
});

const createFundingReference = (overrides: Partial<{
    id: number;
    funder_name: string;
    funder_identifier: string | null;
    funder_identifier_type: string | null;
    award_number: string | null;
    award_uri: string | null;
    award_title: string | null;
    position: number;
}> = {}) => ({
    id: 1,
    funder_name: 'DFG',
    funder_identifier: null,
    funder_identifier_type: null,
    award_number: null,
    award_uri: null,
    award_title: null,
    position: 1,
    ...overrides,
});

const createSubject = (overrides: Partial<{
    id: number;
    subject: string;
    subject_scheme: string | null;
    scheme_uri: string | null;
    value_uri: string | null;
    classification_code: string | null;
}> = {}) => ({
    id: 1,
    subject: 'Geophysics',
    subject_scheme: null,
    scheme_uri: null,
    value_uri: null,
    classification_code: null,
    ...overrides,
});

const defaultProps = {
    descriptions: [createDescription()],
    creators: [],
    contributors: [],
    fundingReferences: [],
    subjects: [],
    resourceId: 123,
};

describe('AbstractSection', () => {
    describe('rendering conditions', () => {
        it('renders when abstract description exists', () => {
            render(<AbstractSection {...defaultProps} />);
            
            expect(screen.getByTestId('abstract-section')).toBeInTheDocument();
            expect(screen.getByText('Abstract')).toBeInTheDocument();
        });

        it('returns null when no abstract description exists', () => {
            const { container } = render(
                <AbstractSection
                    {...defaultProps}
                    descriptions={[createDescription({ description_type: 'Methods' })]}
                />
            );
            
            expect(container).toBeEmptyDOMElement();
        });

        it('returns null when descriptions array is empty', () => {
            const { container } = render(
                <AbstractSection {...defaultProps} descriptions={[]} />
            );
            
            expect(container).toBeEmptyDOMElement();
        });

        it('finds abstract case-insensitively', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    descriptions={[createDescription({ description_type: 'ABSTRACT' })]}
                />
            );
            
            expect(screen.getByTestId('abstract-section')).toBeInTheDocument();
        });

        it('displays the abstract text', () => {
            render(<AbstractSection {...defaultProps} />);
            
            expect(screen.getByTestId('abstract-text')).toHaveTextContent(
                'This is a test abstract describing the dataset.'
            );
        });
    });

    describe('methods section', () => {
        it('renders methods section when methods description exists', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    descriptions={[
                        createDescription(),
                        createDescription({ id: 2, value: 'Seismic reflection profiling was used.', description_type: 'Methods' }),
                    ]}
                />
            );

            expect(screen.getByTestId('methods-section')).toBeInTheDocument();
            expect(screen.getByText('Methods')).toBeInTheDocument();
            expect(screen.getByTestId('methods-text')).toHaveTextContent('Seismic reflection profiling was used.');
        });

        it('does not render methods section when no methods description exists', () => {
            render(<AbstractSection {...defaultProps} />);

            expect(screen.queryByTestId('methods-section')).not.toBeInTheDocument();
        });

        it('finds methods case-insensitively', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    descriptions={[
                        createDescription(),
                        createDescription({ id: 2, value: 'Some methods.', description_type: 'METHODS' }),
                    ]}
                />
            );

            expect(screen.getByTestId('methods-section')).toBeInTheDocument();
        });
    });

    describe('creators section', () => {
        it('renders creators section when creators exist', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    creators={[createCreator()]}
                />
            );
            
            expect(screen.getByTestId('creators-section')).toBeInTheDocument();
            expect(screen.getByText('Creators')).toBeInTheDocument();
        });

        it('does not render creators section when no creators', () => {
            render(<AbstractSection {...defaultProps} />);
            
            expect(screen.queryByTestId('creators-section')).not.toBeInTheDocument();
        });

        it('displays person creator with family name, given name format', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    creators={[createCreator()]}
                />
            );
            
            expect(screen.getByText('Doe, John')).toBeInTheDocument();
        });

        it('displays institution creator with name', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    creators={[createCreator({
                        creatorable: {
                            type: 'Institution',
                            name: 'GFZ German Research Centre',
                        },
                    })]}
                />
            );
            
            expect(screen.getByText('GFZ German Research Centre')).toBeInTheDocument();
        });

        it('renders ORCID link for person with ORCID', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    creators={[createCreator({
                        creatorable: {
                            name_identifier: '0000-0002-1825-0097',
                            name_identifier_scheme: 'ORCID',
                        },
                    })]}
                />
            );
            
            const orcidLink = screen.getByRole('link', { name: /ORCID/i });
            expect(orcidLink).toHaveAttribute('href', 'https://orcid.org/0000-0002-1825-0097');
            expect(orcidLink).toHaveAttribute('target', '_blank');
        });

        it('renders affiliation for creator', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    creators={[createCreator({
                        affiliations: [createAffiliation()],
                    })]}
                />
            );
            
            expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
        });

        it('renders ROR link for affiliation with ROR identifier', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    creators={[createCreator({
                        affiliations: [createAffiliation({
                            affiliation_identifier: 'https://ror.org/04z8jg394',
                            affiliation_identifier_scheme: 'ROR',
                        })],
                    })]}
                />
            );
            
            const rorLink = screen.getByRole('link', { name: /ROR/i });
            expect(rorLink).toHaveAttribute('href', 'https://ror.org/04z8jg394');
        });

        it('displays multiple creators', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    creators={[
                        createCreator({ id: 1, creatorable: { given_name: 'John', family_name: 'Doe' } }),
                        createCreator({ id: 2, creatorable: { id: 2, given_name: 'Jane', family_name: 'Smith' } }),
                    ]}
                />
            );
            
            expect(screen.getByText('Doe, John')).toBeInTheDocument();
            expect(screen.getByText('Smith, Jane')).toBeInTheDocument();
        });
    });

    describe('contributors section', () => {
        const createContributor = (overrides: Partial<{
            id: number;
            position: number;
            contributor_types: string[];
            affiliations: Array<{ id: number; name: string; affiliation_identifier: string | null; affiliation_identifier_scheme: string | null }>;
            contributorable: Partial<{
                type: string;
                id: number;
                given_name: string | null;
                family_name: string | null;
                name_identifier: string | null;
                name_identifier_scheme: string | null;
                name: string | null;
            }>;
        }> = {}) => ({
            id: overrides.id ?? 1,
            position: overrides.position ?? 1,
            contributor_types: overrides.contributor_types ?? [],
            affiliations: overrides.affiliations ?? [],
            contributorable: {
                type: 'Person',
                id: 1,
                given_name: 'Alice' as string | null,
                family_name: 'Wonder' as string | null,
                name_identifier: null as string | null,
                name_identifier_scheme: null as string | null,
                name: null as string | null,
                ...overrides.contributorable,
            },
        });

        it('renders contributors section when contributors exist', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    contributors={[createContributor()]}
                />
            );

            expect(screen.getByTestId('contributors-section')).toBeInTheDocument();
            expect(screen.getByText('Contributors')).toBeInTheDocument();
        });

        it('does not render contributors section when no contributors', () => {
            render(<AbstractSection {...defaultProps} />);

            expect(screen.queryByTestId('contributors-section')).not.toBeInTheDocument();
        });

        it('displays person contributor with family name, given name format', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    contributors={[createContributor()]}
                />
            );

            expect(screen.getByText('Wonder, Alice')).toBeInTheDocument();
        });

        it('displays institution contributor with name', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    contributors={[createContributor({
                        contributorable: {
                            type: 'Institution',
                            name: 'Helmholtz Centre Potsdam',
                        },
                    })]}
                />
            );

            expect(screen.getByText('Helmholtz Centre Potsdam')).toBeInTheDocument();
        });

        it('renders ORCID link for contributor with ORCID', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    contributors={[createContributor({
                        contributorable: {
                            name_identifier: '0000-0001-2345-6789',
                            name_identifier_scheme: 'ORCID',
                        },
                    })]}
                />
            );

            const orcidLink = screen.getByRole('link', { name: /ORCID/i });
            expect(orcidLink).toHaveAttribute('href', 'https://orcid.org/0000-0001-2345-6789');
            expect(orcidLink).toHaveAttribute('target', '_blank');
        });

        it('renders affiliation with ROR link for contributor', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    contributors={[createContributor({
                        affiliations: [createAffiliation({
                            name: 'GFZ Potsdam',
                            affiliation_identifier: 'https://ror.org/04z8jg394',
                            affiliation_identifier_scheme: 'ROR',
                        })],
                    })]}
                />
            );

            expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
            const rorLink = screen.getByRole('link', { name: /ROR/i });
            expect(rorLink).toHaveAttribute('href', 'https://ror.org/04z8jg394');
        });

        it('displays contributor types in brackets', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    contributors={[createContributor({
                        contributor_types: ['DataCollector', 'ProjectLeader'],
                    })]}
                />
            );

            expect(screen.getByText('(DataCollector, ProjectLeader)')).toBeInTheDocument();
        });

        it('does not display contributor type brackets when no types', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    contributors={[createContributor({ contributor_types: [] })]}
                />
            );

            expect(screen.queryByText(/^\(.*\)$/)).not.toBeInTheDocument();
        });
    });

    describe('funding section', () => {
        it('renders funding section when funding references exist', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    fundingReferences={[createFundingReference()]}
                />
            );
            
            expect(screen.getByTestId('funding-section')).toBeInTheDocument();
            expect(screen.getByText('Funders')).toBeInTheDocument();
        });

        it('does not render funding section when no funding references', () => {
            render(<AbstractSection {...defaultProps} />);
            
            expect(screen.queryByTestId('funding-section')).not.toBeInTheDocument();
        });

        it('displays funder name', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    fundingReferences={[createFundingReference()]}
                />
            );
            
            expect(screen.getByText('DFG')).toBeInTheDocument();
        });

        it('renders ROR link for funder with ROR identifier', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    fundingReferences={[createFundingReference({
                        funder_identifier: 'https://ror.org/018mejw64',
                        funder_identifier_type: 'ROR',
                    })]}
                />
            );
            
            const rorLink = screen.getByRole('link', { name: /ROR/i });
            expect(rorLink).toHaveAttribute('href', 'https://ror.org/018mejw64');
        });

        it('renders Crossref Funder link for funder with Crossref identifier', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    fundingReferences={[createFundingReference({
                        funder_identifier: '10.13039/501100001659',
                        funder_identifier_type: 'Crossref Funder ID',
                    })]}
                />
            );
            
            const crossrefLink = screen.getByRole('link', { name: /Crossref Funder ID/i });
            expect(crossrefLink).toHaveAttribute('href', 'https://doi.org/10.13039/501100001659');
        });
    });

    describe('subjects section - unified keywords', () => {
        it('renders keywords section with heading "Keywords" when free keywords exist', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject()]}
                />
            );
            
            expect(screen.getByTestId('subjects-section')).toBeInTheDocument();
            expect(screen.getByText('Keywords')).toBeInTheDocument();
        });

        it('renders keywords section when only thesauri keywords exist', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject({ subject: 'EARTH SCIENCE', subject_scheme: 'Science Keywords' })]}
                />
            );
            
            expect(screen.getByTestId('subjects-section')).toBeInTheDocument();
            expect(screen.getByText('Keywords')).toBeInTheDocument();
        });

        it('does not render keywords section when no subjects exist', () => {
            render(<AbstractSection {...defaultProps} subjects={[]} />);
            
            expect(screen.queryByTestId('subjects-section')).not.toBeInTheDocument();
        });

        it('displays free keyword badges', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[
                        createSubject({ id: 1, subject: 'Geophysics' }),
                        createSubject({ id: 2, subject: 'Seismology', subject_scheme: '' }),
                    ]}
                />
            );
            
            expect(screen.getByText('Geophysics')).toBeInTheDocument();
            expect(screen.getByText('Seismology')).toBeInTheDocument();
        });

        it('renders keyword badges as links to the portal', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[
                        createSubject({ id: 1, subject: 'Geophysics' }),
                    ]}
                />
            );
            
            const link = screen.getByRole('link', { name: /Geophysics/i });
            expect(link).toHaveAttribute('href', '/portal?keywords[]=Geophysics');
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        });

        it('encodes special characters in keyword portal links', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[
                        createSubject({ id: 1, subject: 'Rock & Soil' }),
                    ]}
                />
            );
            
            const link = screen.getByRole('link', { name: /Rock & Soil/i });
            expect(link).toHaveAttribute('href', '/portal?keywords[]=Rock%20%26%20Soil');
        });

        it('renders thesauri keywords as badges', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[
                        createSubject({ id: 1, subject: 'EARTH SCIENCE', subject_scheme: 'Science Keywords' }),
                        createSubject({ id: 2, subject: 'SATELLITES', subject_scheme: 'Platforms' }),
                        createSubject({ id: 3, subject: 'GPS RECEIVERS', subject_scheme: 'Instruments' }),
                        createSubject({ id: 4, subject: 'Rock mechanics', subject_scheme: 'EPOS MSL vocabulary' }),
                    ]}
                />
            );
            
            expect(screen.getByText('EARTH SCIENCE')).toBeInTheDocument();
            expect(screen.getByText('SATELLITES')).toBeInTheDocument();
            expect(screen.getByText('GPS RECEIVERS')).toBeInTheDocument();
            expect(screen.getByText('Rock mechanics')).toBeInTheDocument();
        });

        it('renders GEMET and ICS Chronostratigraphic keywords', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[
                        createSubject({ id: 1, subject: 'Air pollution', subject_scheme: 'GEMET - GEneral Multilingual Environmental Thesaurus' }),
                        createSubject({ id: 2, subject: 'Cenozoic', subject_scheme: 'International Chronostratigraphic Chart' }),
                    ]}
                />
            );
            
            expect(screen.getByText('Air pollution')).toBeInTheDocument();
            expect(screen.getByText('Cenozoic')).toBeInTheDocument();
        });

        it('renders thesauri keyword badges as links to the portal', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject({ subject: 'EARTH SCIENCE', subject_scheme: 'Science Keywords' })]}
                />
            );
            
            const link = screen.getByRole('link', { name: /EARTH SCIENCE/i });
            expect(link).toHaveAttribute('href', '/portal?keywords[]=EARTH%20SCIENCE');
            expect(link).toHaveAttribute('target', '_blank');
        });

        it('renders separator when both thesauri and free keywords exist', () => {
            const { container } = render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[
                        createSubject({ id: 1, subject: 'EARTH SCIENCE', subject_scheme: 'Science Keywords' }),
                        createSubject({ id: 2, subject: 'Free Keyword', subject_scheme: null }),
                    ]}
                />
            );
            
            expect(container.querySelector('hr')).toBeInTheDocument();
        });

        it('does not render separator when only free keywords exist', () => {
            const { container } = render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject({ id: 1, subject: 'Free Keyword', subject_scheme: null })]}
                />
            );
            
            expect(container.querySelector('hr')).not.toBeInTheDocument();
        });

        it('does not render separator when only thesauri keywords exist', () => {
            const { container } = render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject({ id: 1, subject: 'EARTH SCIENCE', subject_scheme: 'Science Keywords' })]}
                />
            );
            
            expect(container.querySelector('hr')).not.toBeInTheDocument();
        });

        it('renders thesauri keywords before free keywords in the DOM', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[
                        createSubject({ id: 1, subject: 'Free Keyword', subject_scheme: null }),
                        createSubject({ id: 2, subject: 'Science KW', subject_scheme: 'Science Keywords' }),
                        createSubject({ id: 3, subject: 'Platform', subject_scheme: 'Platforms' }),
                        createSubject({ id: 4, subject: 'Instrument', subject_scheme: 'Instruments' }),
                        createSubject({ id: 5, subject: 'MSL Term', subject_scheme: 'EPOS MSL vocabulary' }),
                    ]}
                />
            );
            
            expect(screen.getByTestId('thesauri-keywords-list')).toBeInTheDocument();
            expect(screen.getByTestId('keywords-list')).toBeInTheDocument();

            // Thesauri list should come before free keywords list in the DOM
            const thesauriList = screen.getByTestId('thesauri-keywords-list');
            const freeList = screen.getByTestId('keywords-list');
            expect(thesauriList.compareDocumentPosition(freeList) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });
    });

    describe('download metadata section', () => {
        it('renders download metadata section', () => {
            render(<AbstractSection {...defaultProps} />);
            
            expect(screen.getByText('Download Metadata')).toBeInTheDocument();
        });

        it('renders DataCite logo', () => {
            render(<AbstractSection {...defaultProps} />);
            
            expect(screen.getByAltText('DataCite')).toBeInTheDocument();
        });

        it('renders XML download link with correct href', () => {
            render(<AbstractSection {...defaultProps} />);
            
            const xmlLink = screen.getByRole('link', { name: /XML/i });
            expect(xmlLink).toHaveAttribute('href', '/resources/123/export-datacite-xml');
        });

        it('renders JSON download link with correct href', () => {
            render(<AbstractSection {...defaultProps} />);
            
            const jsonLink = screen.getByRole('link', { name: /JSON/i });
            expect(jsonLink).toHaveAttribute('href', '/resources/123/export-datacite-json');
        });

        it('uses correct resourceId in download links', () => {
            render(<AbstractSection {...defaultProps} resourceId={456} />);
            
            const xmlLink = screen.getByRole('link', { name: /XML/i });
            const jsonLink = screen.getByRole('link', { name: /JSON/i });
            
            expect(xmlLink).toHaveAttribute('href', '/resources/456/export-datacite-xml');
            expect(jsonLink).toHaveAttribute('href', '/resources/456/export-datacite-json');
        });
    });
});

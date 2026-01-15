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
    creatorable: {
        type: string;
        id: number;
        given_name?: string;
        family_name?: string;
        name_identifier?: string;
        name_identifier_scheme?: string;
        name?: string;
    };
}> = {}) => ({
    id: 1,
    position: 1,
    affiliations: [],
    creatorable: {
        type: 'Person',
        id: 1,
        given_name: 'John',
        family_name: 'Doe',
    },
    ...overrides,
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
                            id: 1,
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
                            type: 'Person',
                            id: 1,
                            given_name: 'John',
                            family_name: 'Doe',
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
                        createCreator({ id: 1, creatorable: { type: 'Person', id: 1, given_name: 'John', family_name: 'Doe' } }),
                        createCreator({ id: 2, creatorable: { type: 'Person', id: 2, given_name: 'Jane', family_name: 'Smith' } }),
                    ]}
                />
            );
            
            expect(screen.getByText('Doe, John')).toBeInTheDocument();
            expect(screen.getByText('Smith, Jane')).toBeInTheDocument();
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

    describe('subjects section - free keywords', () => {
        it('renders free keywords section when subjects without scheme exist', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject()]}
                />
            );
            
            expect(screen.getByTestId('subjects-section')).toBeInTheDocument();
            expect(screen.getByText('Free Keywords')).toBeInTheDocument();
        });

        it('does not render free keywords when all subjects have schemes', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject({ subject_scheme: 'Science Keywords' })]}
                />
            );
            
            expect(screen.queryByTestId('subjects-section')).not.toBeInTheDocument();
        });

        it('displays keyword badges', () => {
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
    });

    describe('subjects section - GCMD keywords', () => {
        it('renders GCMD Science Keywords section', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject({ subject: 'EARTH SCIENCE', subject_scheme: 'Science Keywords' })]}
                />
            );
            
            expect(screen.getByText('GCMD Science Keywords')).toBeInTheDocument();
            expect(screen.getByText('EARTH SCIENCE')).toBeInTheDocument();
        });

        it('renders GCMD Platforms section', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject({ subject: 'SATELLITES', subject_scheme: 'Platforms' })]}
                />
            );
            
            expect(screen.getByText('GCMD Platforms')).toBeInTheDocument();
            expect(screen.getByText('SATELLITES')).toBeInTheDocument();
        });

        it('renders GCMD Instruments section', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject({ subject: 'GPS RECEIVERS', subject_scheme: 'Instruments' })]}
                />
            );
            
            expect(screen.getByText('GCMD Instruments')).toBeInTheDocument();
            expect(screen.getByText('GPS RECEIVERS')).toBeInTheDocument();
        });

        it('renders MSL Vocabularies section', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[createSubject({ subject: 'Rock mechanics', subject_scheme: 'msl' })]}
                />
            );
            
            expect(screen.getByText('MSL Vocabularies')).toBeInTheDocument();
            expect(screen.getByText('Rock mechanics')).toBeInTheDocument();
        });

        it('groups subjects by scheme correctly', () => {
            render(
                <AbstractSection
                    {...defaultProps}
                    subjects={[
                        createSubject({ id: 1, subject: 'Free Keyword', subject_scheme: null }),
                        createSubject({ id: 2, subject: 'Science KW', subject_scheme: 'Science Keywords' }),
                        createSubject({ id: 3, subject: 'Platform', subject_scheme: 'Platforms' }),
                        createSubject({ id: 4, subject: 'Instrument', subject_scheme: 'Instruments' }),
                        createSubject({ id: 5, subject: 'MSL Term', subject_scheme: 'msl' }),
                    ]}
                />
            );
            
            expect(screen.getByText('Free Keywords')).toBeInTheDocument();
            expect(screen.getByText('GCMD Science Keywords')).toBeInTheDocument();
            expect(screen.getByText('GCMD Platforms')).toBeInTheDocument();
            expect(screen.getByText('GCMD Instruments')).toBeInTheDocument();
            expect(screen.getByText('MSL Vocabularies')).toBeInTheDocument();
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

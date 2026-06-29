/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { ContributorsSection } from '@/pages/LandingPages/components/ContributorsSection';
import type { LandingPageContributor } from '@/types/landing-page';

const mockContributor = (overrides: Partial<LandingPageContributor> = {}): LandingPageContributor => ({
    id: 1,
    position: 1,
    contributorable: {
        id: 1,
        type: 'Person',
        name: 'Doe, John',
        given_name: 'John',
        family_name: 'Doe',
        name_identifier: null,
        name_identifier_scheme: null,
    },
    affiliations: [],
    contributor_types: [],
    ...overrides,
});

describe('ContributorsSection', () => {
    it('returns null when no contributors', () => {
        const { container } = render(<ContributorsSection contributors={[]} />);
        expect(container.innerHTML).toBe('');
    });

    it('renders contributors list', () => {
        render(<ContributorsSection contributors={[mockContributor()]} />);
        expect(screen.getByTestId('contributors-section')).toBeInTheDocument();
        expect(screen.getByText('Doe, John')).toBeInTheDocument();
    });

    it('renders contributor types', () => {
        const contributor = mockContributor({
            contributor_types: ['DataCollector', 'Researcher'],
        });
        render(<ContributorsSection contributors={[contributor]} />);
        expect(screen.getByText('(DataCollector, Researcher)')).toBeInTheDocument();
    });

    it('renders ORCID link when contributor has ORCID', () => {
        const contributor = mockContributor({
            contributorable: {
                id: 1,
                type: 'Person',
                name: 'Doe, John',
                given_name: 'John',
                family_name: 'Doe',
                name_identifier: 'www.orcid.org/0000-0001-2345-6789',
                name_identifier_scheme: 'ORCID',
            },
        });
        render(<ContributorsSection contributors={[contributor]} />);
        const orcidLink = screen.getByLabelText('ORCID profile of Doe, John');
        expect(orcidLink).toHaveAttribute('href', 'https://orcid.org/0000-0001-2345-6789');
        expect(orcidLink).toHaveClass('min-h-11', 'min-w-11', 'p-3');
    });

    it('renders all contributor affiliations and keeps roles at the end', () => {
        const contributor = mockContributor({
            contributorable: {
                id: 5,
                type: 'Person',
                name: 'Cinzano, Pierantonio',
                given_name: 'Pierantonio',
                family_name: 'Cinzano',
                name_identifier: '0000-0003-1111-2222',
                name_identifier_scheme: 'ORCID',
            },
            contributor_types: ['DataCollector', 'ProjectLeader'],
            affiliations: [
                {
                    id: 1,
                    name: "ISTIL - Istituto di Scienza e Tecnologia dell'Inquinamento Luminoso",
                    affiliation_identifier: ' https://ror.org/01abcde23 ',
                    affiliation_identifier_scheme: 'ROR',
                },
                {
                    id: 2,
                    name: 'Light Pollution Science and Technology Institute, Thiene, Italy',
                    affiliation_identifier: null,
                    affiliation_identifier_scheme: null,
                },
            ],
        });

        render(<ContributorsSection contributors={[contributor]} />);

        const listItem = screen.getByRole('listitem');
        expect(listItem).not.toHaveClass('flex');
        expect(listItem).toHaveClass('leading-6');
        expect(listItem).toHaveTextContent('Cinzano, Pierantonio');
        expect(listItem).toHaveTextContent('(DataCollector, ProjectLeader)');
        expect(screen.getByText("ISTIL - Istituto di Scienza e Tecnologia dell'Inquinamento Luminoso")).toBeInTheDocument();
        expect(screen.getByText('Light Pollution Science and Technology Institute, Thiene, Italy')).toBeInTheDocument();
        const orcidLink = screen.getByLabelText('ORCID profile of Cinzano, Pierantonio');
        expect(orcidLink).toHaveAttribute('href', 'https://orcid.org/0000-0003-1111-2222');
        expect(orcidLink).toHaveClass('min-h-11', 'min-w-11', 'p-3');
        const rorLink = screen.getByLabelText("ROR profile of ISTIL - Istituto di Scienza e Tecnologia dell'Inquinamento Luminoso");
        expect(rorLink).toHaveAttribute('href', 'https://ror.org/01abcde23');
        expect(rorLink).toHaveClass('min-h-11', 'min-w-11', 'p-3');
    });

    it('does not show expand button when under threshold', () => {
        const contributors = Array.from({ length: 5 }, (_, i) => mockContributor({ id: i + 1 }));
        render(<ContributorsSection contributors={contributors} />);
        expect(screen.queryByText(/Show all/)).not.toBeInTheDocument();
    });

    it('shows expand button when above threshold', () => {
        const contributors = Array.from({ length: 12 }, (_, i) => mockContributor({ id: i + 1 }));
        render(<ContributorsSection contributors={contributors} displayLimit={10} />);
        expect(screen.getByText('Showing 10 of 12 contributors')).toBeInTheDocument();
        expect(screen.getByText('Show all 12 contributors')).toBeInTheDocument();
    });
});

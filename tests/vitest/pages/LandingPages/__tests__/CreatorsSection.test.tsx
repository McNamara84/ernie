/**
 * @vitest-environment jsdom
 */
import { fireEvent, render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { CreatorsSection } from '@/pages/LandingPages/components/CreatorsSection';
import type { LandingPageCreator } from '@/types/landing-page';

const mockCreator = (overrides: Partial<LandingPageCreator> = {}): LandingPageCreator => ({
    id: 1,
    position: 1,
    creatorable: {
        id: 1,
        type: 'Person',
        name: 'Doe, John',
        given_name: 'John',
        family_name: 'Doe',
        name_identifier: null,
        name_identifier_scheme: null,
    },
    affiliations: [],
    ...overrides,
});

describe('CreatorsSection', () => {
    it('returns null when no creators', () => {
        const { container } = render(<CreatorsSection creators={[]} />);
        expect(container.innerHTML).toBe('');
    });

    it('renders creators list', () => {
        render(<CreatorsSection creators={[mockCreator()]} />);
        expect(screen.getByTestId('creators-section')).toBeInTheDocument();
        expect(screen.getByText('Doe, John')).toBeInTheDocument();
    });

    it('renders ORCID link when creator has ORCID', () => {
        const creator = mockCreator({
            creatorable: {
                id: 2,
                type: 'Person',
                name: 'Doe, John',
                given_name: 'John',
                family_name: 'Doe',
                name_identifier: '0000-0001-2345-6789',
                name_identifier_scheme: 'ORCID',
            },
        });
        render(<CreatorsSection creators={[creator]} />);
        const orcidLink = screen.getByLabelText('ORCID profile of Doe, John');
        expect(orcidLink).toHaveAttribute('href', 'https://orcid.org/0000-0001-2345-6789');
    });

    it('renders affiliation with ROR link', () => {
        const creator = mockCreator({
            affiliations: [
                {
                    id: 1,
                    name: 'GFZ Potsdam',
                    affiliation_identifier: 'https://ror.org/04z8jg394',
                    affiliation_identifier_scheme: 'ROR',
                },
            ],
        });
        render(<CreatorsSection creators={[creator]} />);
        expect(screen.getByText('GFZ Potsdam')).toBeInTheDocument();
        expect(screen.getByLabelText('ROR profile of GFZ Potsdam')).toBeInTheDocument();
    });

    it('renders all creator affiliations in a prose list item', () => {
        const creator = mockCreator({
            creatorable: {
                id: 2,
                type: 'Person',
                name: 'Falchi, Fabio',
                given_name: 'Fabio',
                family_name: 'Falchi',
                name_identifier: '0000-0002-1111-2222',
                name_identifier_scheme: 'ORCID',
            },
            affiliations: [
                {
                    id: 1,
                    name: "ISTIL - Istituto di Scienza e Tecnologia dell'Inquinamento Luminoso",
                    affiliation_identifier: 'https://ror.org/01abcde23',
                    affiliation_identifier_scheme: 'ROR',
                },
                {
                    id: 2,
                    name: 'Light Pollution Science and Technology Institute, Thiene, Italy',
                    affiliation_identifier: 'https://ror.org/04z8jg394',
                    affiliation_identifier_scheme: 'ROR',
                },
            ],
        });

        render(<CreatorsSection creators={[creator]} />);

        const listItem = screen.getByRole('listitem');
        expect(listItem).not.toHaveClass('flex');
        expect(listItem).toHaveClass('leading-6');
        expect(listItem).toHaveTextContent('Falchi, Fabio');
        expect(screen.getByText("ISTIL - Istituto di Scienza e Tecnologia dell'Inquinamento Luminoso")).toBeInTheDocument();
        expect(screen.getByText('Light Pollution Science and Technology Institute, Thiene, Italy')).toBeInTheDocument();
        expect(screen.getByLabelText('ORCID profile of Falchi, Fabio')).toHaveAttribute('href', 'https://orcid.org/0000-0002-1111-2222');
        expect(screen.getByLabelText("ROR profile of ISTIL - Istituto di Scienza e Tecnologia dell'Inquinamento Luminoso")).toHaveAttribute(
            'href',
            'https://ror.org/01abcde23',
        );
        expect(screen.getByLabelText('ROR profile of Light Pollution Science and Technology Institute, Thiene, Italy')).toHaveAttribute(
            'href',
            'https://ror.org/04z8jg394',
        );
    });

    it('renders institution name for non-person creators', () => {
        const creator = mockCreator({
            creatorable: {
                id: 3,
                type: 'Institution',
                name: 'GFZ German Research Centre',
                given_name: null,
                family_name: null,
                name_identifier: null,
                name_identifier_scheme: null,
            },
        });
        render(<CreatorsSection creators={[creator]} />);
        expect(screen.getByText('GFZ German Research Centre')).toBeInTheDocument();
    });

    it('renders multiple creators', () => {
        const creators = [
            mockCreator({ id: 1 }),
            mockCreator({
                id: 2,
                creatorable: {
                    id: 4,
                    type: 'Person',
                    name: 'Smith, Jane',
                    given_name: 'Jane',
                    family_name: 'Smith',
                    name_identifier: null,
                    name_identifier_scheme: null,
                },
            }),
        ];
        render(<CreatorsSection creators={creators} />);
        expect(screen.getByText('Doe, John')).toBeInTheDocument();
        expect(screen.getByText('Smith, Jane')).toBeInTheDocument();
    });

    it('limits initially visible creators and shows the full list on request', () => {
        const creators = Array.from({ length: 4 }, (_, i) =>
            mockCreator({
                id: i + 1,
                creatorable: {
                    id: i + 1,
                    type: 'Person',
                    name: `Creator, ${i + 1}`,
                    given_name: `${i + 1}`,
                    family_name: 'Creator',
                    name_identifier: null,
                    name_identifier_scheme: null,
                },
            }),
        );

        render(<CreatorsSection creators={creators} displayLimit={2} />);

        expect(screen.getByText('Showing 2 of 4 creators')).toBeInTheDocument();
        const listItems = screen.getAllByRole('listitem');
        expect(listItems).toHaveLength(4);
        expect(listItems.filter((item) => !item.classList.contains('hidden'))).toHaveLength(2);

        fireEvent.click(screen.getByRole('button', { name: /Show all 4 creators/i }));

        expect(screen.getByText('Showing all 4 creators')).toBeInTheDocument();
        expect(screen.getAllByRole('listitem').filter((item) => !item.classList.contains('hidden'))).toHaveLength(4);
    });
});

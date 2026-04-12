/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
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
});

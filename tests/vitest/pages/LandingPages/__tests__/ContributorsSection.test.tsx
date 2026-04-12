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
                name_identifier: '0000-0001-2345-6789',
                name_identifier_scheme: 'ORCID',
            },
        });
        render(<ContributorsSection contributors={[contributor]} />);
        expect(screen.getByLabelText('ORCID profile of Doe, John')).toBeInTheDocument();
    });

    it('does not show expand button when under threshold', () => {
        const contributors = Array.from({ length: 5 }, (_, i) => mockContributor({ id: i + 1 }));
        render(<ContributorsSection contributors={contributors} />);
        expect(screen.queryByText(/Show all/)).not.toBeInTheDocument();
    });

    it('shows expand button when above threshold', () => {
        const contributors = Array.from({ length: 12 }, (_, i) => mockContributor({ id: i + 1 }));
        render(<ContributorsSection contributors={contributors} />);
        expect(screen.getByText('Show all 12 contributors')).toBeInTheDocument();
    });
});

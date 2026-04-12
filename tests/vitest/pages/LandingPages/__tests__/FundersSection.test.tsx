/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { FundersSection } from '@/pages/LandingPages/components/FundersSection';
import type { LandingPageFundingReference } from '@/types/landing-page';

const mockFunder = (overrides: Partial<LandingPageFundingReference> = {}): LandingPageFundingReference => ({
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

describe('FundersSection', () => {
    it('returns null when no funding references', () => {
        const { container } = render(<FundersSection fundingReferences={[]} />);
        expect(container.innerHTML).toBe('');
    });

    it('renders funder list', () => {
        render(<FundersSection fundingReferences={[mockFunder()]} />);
        expect(screen.getByTestId('funding-section')).toBeInTheDocument();
        expect(screen.getByText('DFG')).toBeInTheDocument();
    });

    it('renders ROR link for funder with ROR identifier', () => {
        const funder = mockFunder({
            funder_identifier: 'https://ror.org/018mejw64',
            funder_identifier_type: 'ROR',
        });
        render(<FundersSection fundingReferences={[funder]} />);
        expect(screen.getByLabelText('ROR profile of DFG')).toHaveAttribute(
            'href',
            'https://ror.org/018mejw64',
        );
    });

    it('renders Crossref link for funder with Crossref Funder ID', () => {
        const funder = mockFunder({
            funder_name: 'EU Commission',
            funder_identifier: '10.13039/501100000780',
            funder_identifier_type: 'Crossref Funder ID',
        });
        render(<FundersSection fundingReferences={[funder]} />);
        expect(screen.getByLabelText('Crossref Funder ID for EU Commission')).toHaveAttribute(
            'href',
            'https://doi.org/10.13039/501100000780',
        );
    });

    it('does not show expand button when under threshold', () => {
        const funders = Array.from({ length: 5 }, (_, i) =>
            mockFunder({ id: i + 1, funder_name: `Funder ${i + 1}` }),
        );
        render(<FundersSection fundingReferences={funders} />);
        expect(screen.queryByText(/Show all/)).not.toBeInTheDocument();
    });

    it('shows expand button when above threshold', () => {
        const funders = Array.from({ length: 15 }, (_, i) =>
            mockFunder({ id: i + 1, funder_name: `Funder ${i + 1}` }),
        );
        render(<FundersSection fundingReferences={funders} />);
        expect(screen.getByText('Show all 15 funders')).toBeInTheDocument();
    });
});

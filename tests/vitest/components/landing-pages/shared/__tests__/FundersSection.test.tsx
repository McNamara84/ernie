import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import FundersSection from '@/components/landing-pages/shared/FundersSection';

describe('FundersSection', () => {
    const mockResourceWithFunding = {
        funding_references: [
            {
                id: 1,
                funder_name: 'Deutsche Forschungsgemeinschaft (DFG)',
                funder_identifier: '501100001659',
                funder_identifier_type: 'Crossref Funder ID',
                award_number: 'DFG-12345',
                award_title: 'Research Project on Geosciences',
                award_uri: 'https://example.com/award/12345',
            },
            {
                id: 2,
                funder_name: 'European Research Council',
                funder_identifier: 'https://ror.org/0472cxd90',
                funder_identifier_type: 'ROR',
                award_number: 'ERC-2023-12345',
            },
        ],
    };

    const mockResourceSingleFunder = {
        funding_references: [
            {
                id: 1,
                funder_name: 'National Science Foundation',
                award_number: 'NSF-12345',
            },
        ],
    };

    const mockResourceMinimalFunder = {
        funding_references: [
            {
                funder_name: 'Generic Funder',
            },
        ],
    };

    const mockResourceNoFunding = {
        funding_references: [],
    };

    const mockResourceMissingFunding = {};

    describe('Rendering', () => {
        it('should render funders section with heading', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            expect(screen.getByRole('heading', { name: /funding/i })).toBeInTheDocument();
        });

        it('should render custom heading', () => {
            render(<FundersSection resource={mockResourceWithFunding} heading="Sponsors" />);

            expect(screen.getByRole('heading', { name: /sponsors/i })).toBeInTheDocument();
        });

        it('should not render when no funding references', () => {
            const { container } = render(<FundersSection resource={mockResourceNoFunding} />);

            expect(container).toBeEmptyDOMElement();
        });

        it('should not render when funding_references property missing', () => {
            const { container } = render(<FundersSection resource={mockResourceMissingFunding} />);

            expect(container).toBeEmptyDOMElement();
        });
    });

    describe('Funder Display', () => {
        it('should display funder names', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            expect(
                screen.getByText('Deutsche Forschungsgemeinschaft (DFG)'),
            ).toBeInTheDocument();
            expect(screen.getByText('European Research Council')).toBeInTheDocument();
        });

        it('should display award numbers', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            expect(screen.getByText('DFG-12345')).toBeInTheDocument();
            expect(screen.getByText('ERC-2023-12345')).toBeInTheDocument();
        });

        it('should display award titles', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            expect(screen.getByText('Research Project on Geosciences')).toBeInTheDocument();
        });

        it('should display award URI links', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            const awardLink = screen.getByRole('link', { name: /view award details/i });
            expect(awardLink).toHaveAttribute('href', 'https://example.com/award/12345');
            expect(awardLink).toHaveAttribute('target', '_blank');
            expect(awardLink).toHaveAttribute('rel', 'noopener noreferrer');
        });

        it('should handle funder without award number', () => {
            const resourceWithoutAward = {
                funding_references: [
                    {
                        funder_name: 'Test Funder',
                    },
                ],
            };

            render(<FundersSection resource={resourceWithoutAward} />);

            expect(screen.getByText('Test Funder')).toBeInTheDocument();
            expect(screen.queryByText(/award number/i)).not.toBeInTheDocument();
        });

        it('should handle funder without award title', () => {
            render(<FundersSection resource={mockResourceSingleFunder} />);

            expect(screen.queryByText(/award title/i)).not.toBeInTheDocument();
        });

        it('should handle funder without award URI', () => {
            render(<FundersSection resource={mockResourceSingleFunder} />);

            expect(screen.queryByRole('link', { name: /view award details/i })).not.toBeInTheDocument();
        });
    });

    describe('Funder Identifiers', () => {
        it('should display funder identifier by default', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            expect(screen.getByText(/Crossref Funder ID: 501100001659/i)).toBeInTheDocument();
        });

        it('should format ROR identifier correctly', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            expect(screen.getByText(/ROR: 0472cxd90/i)).toBeInTheDocument();
        });

        it('should hide identifiers when showIdentifiers=false', () => {
            render(<FundersSection resource={mockResourceWithFunding} showIdentifiers={false} />);

            expect(
                screen.queryByText(/Crossref Funder ID: 501100001659/i),
            ).not.toBeInTheDocument();
            expect(screen.queryByText(/ROR: 0472cxd90/i)).not.toBeInTheDocument();
        });

        it('should not show identifier section without both identifier and type', () => {
            const resourceWithoutType = {
                funding_references: [
                    {
                        funder_name: 'Test Funder',
                        funder_identifier: '12345',
                    },
                ],
            };

            render(<FundersSection resource={resourceWithoutType} />);

            expect(screen.queryByText(/12345/)).not.toBeInTheDocument();
        });
    });

    describe('Funder Links', () => {
        it('should create clickable link for ROR identifier', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            const ercLink = screen.getByRole('link', { name: /european research council/i });
            expect(ercLink).toHaveAttribute('href', 'https://ror.org/0472cxd90');
            expect(ercLink).toHaveAttribute('target', '_blank');
        });

        it('should create clickable link for Crossref Funder ID', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            const dfgLink = screen.getByRole('link', {
                name: /deutsche forschungsgemeinschaft/i,
            });
            expect(dfgLink).toHaveAttribute('href', 'https://doi.org/501100001659');
        });

        it('should handle URL identifiers as-is', () => {
            const resourceWithUrl = {
                funding_references: [
                    {
                        funder_name: 'Test Funder',
                        funder_identifier: 'https://example.com/funder/123',
                        funder_identifier_type: 'URL',
                    },
                ],
            };

            render(<FundersSection resource={resourceWithUrl} />);

            const funderLink = screen.getByRole('link', { name: /test funder/i });
            expect(funderLink).toHaveAttribute('href', 'https://example.com/funder/123');
        });

        it('should not create link when no identifier', () => {
            render(<FundersSection resource={mockResourceSingleFunder} />);

            expect(screen.getByText('National Science Foundation')).toBeInTheDocument();
            expect(
                screen.queryByRole('link', { name: /national science foundation/i }),
            ).not.toBeInTheDocument();
        });
    });

    describe('Multiple Funders', () => {
        it('should display all funders in grid', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            const funderCards = screen.getAllByRole('heading', { level: 3 });
            expect(funderCards.length).toBeGreaterThanOrEqual(2);
        });

        it('should show info text for multiple funders', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            expect(
                screen.getByText(/this dataset was supported by 2 funding sources/i),
            ).toBeInTheDocument();
        });

        it('should not show info text for single funder', () => {
            render(<FundersSection resource={mockResourceSingleFunder} />);

            expect(
                screen.queryByText(/this dataset was supported by/i),
            ).not.toBeInTheDocument();
        });
    });

    describe('Layout and Styling', () => {
        it('should use card layout for funders', () => {
            const { container } = render(<FundersSection resource={mockResourceWithFunding} />);

            const cards = container.querySelectorAll('.rounded-lg.border');
            expect(cards.length).toBe(2);
        });

        it('should show award icon', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });

        it('should use monospace font for award numbers', () => {
            const { container } = render(<FundersSection resource={mockResourceWithFunding} />);

            const awardNumber = container.querySelector('.font-mono');
            expect(awardNumber).toBeInTheDocument();
            expect(awardNumber?.textContent).toBe('DFG-12345');
        });
    });

    describe('Edge Cases', () => {
        it('should handle funder with minimal data', () => {
            render(<FundersSection resource={mockResourceMinimalFunder} />);

            expect(screen.getByText('Generic Funder')).toBeInTheDocument();
        });

        it('should handle funders without IDs', () => {
            const resourceWithoutIds = {
                funding_references: [
                    { funder_name: 'Funder 1' },
                    { funder_name: 'Funder 2' },
                ],
            };

            render(<FundersSection resource={resourceWithoutIds} />);

            expect(screen.getByText('Funder 1')).toBeInTheDocument();
            expect(screen.getByText('Funder 2')).toBeInTheDocument();
        });

        it('should handle very long funder names', () => {
            const resourceWithLongName = {
                funding_references: [
                    {
                        funder_name:
                            'Very Long Funder Name That Should Be Truncated When Displayed In The UI',
                    },
                ],
            };

            render(<FundersSection resource={resourceWithLongName} />);

            expect(
                screen.getByText(
                    /Very Long Funder Name That Should Be Truncated When Displayed In The UI/,
                ),
            ).toBeInTheDocument();
        });

        it('should handle very long award numbers', () => {
            const resourceWithLongAward = {
                funding_references: [
                    {
                        funder_name: 'Test Funder',
                        award_number: 'VERY-LONG-AWARD-NUMBER-12345678901234567890',
                    },
                ],
            };

            render(<FundersSection resource={resourceWithLongAward} />);

            expect(
                screen.getByText('VERY-LONG-AWARD-NUMBER-12345678901234567890'),
            ).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have proper aria-label on section', () => {
            const { container } = render(<FundersSection resource={mockResourceWithFunding} />);

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Funding');
        });

        it('should have custom aria-label when heading is custom', () => {
            const { container } = render(
                <FundersSection resource={mockResourceWithFunding} heading="Financial Support" />,
            );

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Financial Support');
        });

        it('should have aria-hidden on decorative icons', () => {
            render(<FundersSection resource={mockResourceWithFunding} />);

            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });
    });
});
